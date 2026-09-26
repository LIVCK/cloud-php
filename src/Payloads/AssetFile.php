<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Payloads;

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Http\MultipartPart;
use Psr\Http\Message\StreamInterface;

/**
 * An image for a status page's logo, dark logo or favicon, from a path, a stream or memory.
 *
 *     AssetFile::fromPath('/srv/brands/4711/logo.svg')
 *     AssetFile::fromContents($bytes, 'logo.png')
 *     AssetFile::fromStream($upload->getStream(), $upload->getClientFilename())
 *
 * The server identifies the format from the content, but an SVG is also recognised by its
 * `.svg` extension and the part's Content-Type is derived from the extension: give files
 * that live under a temporary name (a form upload in `/tmp/php…`) their real filename.
 * Accepted formats and sizes: see {@see \LIVCK\Cloud\Enums\AssetType}.
 */
final readonly class AssetFile
{
    /** The multipart field the server reads the file from. */
    public const string FIELD = 'file';

    private function __construct(
        private MultipartPart $part,
    ) {}

    /**
     * A file on disk, streamed rather than read into memory.
     *
     * @param string|null $filename sent to the server instead of the path's basename
     */
    public static function fromPath(string $path, ?string $filename = null): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a file.', $path));
        }

        return new self(MultipartPart::file(self::FIELD, $path, self::filename($filename ?? basename($path))));
    }

    /** File contents already in memory. */
    public static function fromContents(string $contents, string $filename): self
    {
        return new self(MultipartPart::contents(self::FIELD, $contents, self::filename($filename)));
    }

    /**
     * A PSR-7 stream, or a PHP stream resource, which is read into memory right away (assets
     * are small). Seekable streams are read from the start. A stream that cannot seek is used
     * up by the first upload: build a new AssetFile for the next one.
     *
     * @param StreamInterface|resource $stream
     */
    public static function fromStream(mixed $stream, string $filename): self
    {
        $filename = self::filename($filename);

        if ($stream instanceof StreamInterface) {
            return new self(MultipartPart::stream(self::FIELD, $stream, $filename));
        }

        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException(sprintf('Expected a PSR-7 stream or an open stream resource, got %s.', get_debug_type($stream)));
        }

        if (stream_get_meta_data($stream)['seekable']) {
            rewind($stream);
        }

        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw new InvalidArgumentException('The stream could not be read.');
        }

        return new self(MultipartPart::contents(self::FIELD, $contents, $filename));
    }

    /** The multipart part carrying the file, named {@see self::FIELD}. */
    public function toMultipartPart(): MultipartPart
    {
        return $this->part;
    }

    /**
     * The filename goes into a quoted multipart header, so quotes, backslashes and control
     * characters (a name taken from an end customer's upload, say) could break out of it.
     */
    private static function filename(string $filename): string
    {
        if (trim($filename) === '') {
            throw new InvalidArgumentException('An asset file needs a filename, e.g. "logo.png".');
        }

        if (preg_match('/["\\\\\x00-\x1F\x7F]/', $filename) === 1) {
            throw new InvalidArgumentException('A filename must not contain quotes, backslashes or control characters.');
        }

        return $filename;
    }
}
