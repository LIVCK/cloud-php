<?php

declare(strict_types=1);

use Http\Discovery\Psr17FactoryDiscovery;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Payloads\AssetFile;

it('reads contents from memory under the given filename', function (): void {
    $part = AssetFile::fromContents('PNGDATA', 'logo.png')->toMultipartPart();

    expect($part->name)->toBe('file')
        ->and($part->name)->toBe(AssetFile::FIELD)
        ->and($part->contents)->toBe('PNGDATA')
        ->and($part->filename)->toBe('logo.png')
        ->and($part->contentType)->toBeNull();
});

it('streams a file from disk under its basename or a given filename', function (): void {
    $path = sys_get_temp_dir() . '/livck-sdk-' . bin2hex(random_bytes(6));
    file_put_contents($path, 'SVGDATA');

    try {
        $own = AssetFile::fromPath($path)->toMultipartPart();
        $renamed = AssetFile::fromPath($path, 'logo.svg')->toMultipartPart();

        expect($own->filename)->toBe(basename($path))
            ->and(is_resource($own->contents))->toBeTrue()
            ->and($renamed->filename)->toBe('logo.svg');
    } finally {
        unlink($path);
    }
});

it('refuses a path that is not a readable file', function (string $path): void {
    expect(fn(): AssetFile => AssetFile::fromPath($path))->toThrow(InvalidArgumentException::class, 'is not a file');
})->with([
    'missing' => [sys_get_temp_dir() . '/livck-sdk-missing-' . bin2hex(random_bytes(6)) . '.png'],
    'directory' => [sys_get_temp_dir()],
]);

it('passes a PSR-7 stream through', function (): void {
    $stream = Psr17FactoryDiscovery::findStreamFactory()->createStream('ICODATA');
    $part = AssetFile::fromStream($stream, 'favicon.ico')->toMultipartPart();

    expect($part->contents)->toBe($stream)
        ->and($part->filename)->toBe('favicon.ico');
});

it('reads a stream resource from its start', function (): void {
    $handle = fopen('php://memory', 'r+b');
    assert(is_resource($handle));
    fwrite($handle, 'WEBPDATA');

    $part = AssetFile::fromStream($handle, 'logo.webp')->toMultipartPart();
    fclose($handle);

    expect($part->contents)->toBe('WEBPDATA')
        ->and($part->filename)->toBe('logo.webp');
});

it('refuses a closed resource and a resource that is no stream', function (): void {
    $closed = fopen('php://memory', 'rb');
    assert(is_resource($closed));
    fclose($closed);

    expect(fn(): AssetFile => AssetFile::fromStream($closed, 'logo.png'))
        ->toThrow(InvalidArgumentException::class, 'Expected a PSR-7 stream or an open stream resource, got resource (closed)')
        ->and(fn(): AssetFile => AssetFile::fromStream(stream_context_create(), 'logo.png'))
        ->toThrow(InvalidArgumentException::class, 'Expected a PSR-7 stream or an open stream resource');
});

it('refuses filenames that could break out of the multipart header', function (string $filename, string $message): void {
    expect(fn(): AssetFile => AssetFile::fromContents('PNGDATA', $filename))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank' => [' ', 'needs a filename'],
    'quote' => ['logo".png', 'must not contain quotes'],
    'backslash' => ['logo\\.png', 'must not contain quotes'],
    'line break' => ["logo.png\r\nContent-Type: text/html", 'must not contain quotes'],
    'null byte' => ["logo\0.png", 'must not contain quotes'],
]);

it('keeps names outside ASCII', function (): void {
    expect(AssetFile::fromContents('PNGDATA', 'Logo Müller & Söhne.png')->toMultipartPart()->filename)->toBe('Logo Müller & Söhne.png');
});
