<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Http;

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * One part of a `multipart/form-data` body (the statuspage asset upload).
 */
final readonly class MultipartPart
{
    /**
     * @param string|resource|StreamInterface $contents
     */
    private function __construct(
        public string $name,
        public mixed $contents,
        public ?string $filename = null,
        public ?string $contentType = null,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A multipart field needs a name.');
        }
    }

    /** A plain form field. */
    public static function text(string $name, string $value): self
    {
        return new self($name, $value);
    }

    /** A file from disk, streamed rather than read into memory. */
    public static function file(string $name, string $path, ?string $filename = null, ?string $contentType = null): self
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new InvalidArgumentException(sprintf('Cannot read "%s".', $path));
        }

        return new self($name, $handle, $filename ?? basename($path), $contentType);
    }

    /** File contents already in memory. */
    public static function contents(string $name, string $contents, string $filename, ?string $contentType = null): self
    {
        return new self($name, $contents, $filename, $contentType);
    }

    /** A PSR-7 stream. */
    public static function stream(string $name, StreamInterface $stream, ?string $filename = null, ?string $contentType = null): self
    {
        return new self($name, $stream, $filename, $contentType);
    }
}
