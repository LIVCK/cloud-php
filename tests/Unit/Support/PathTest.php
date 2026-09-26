<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Path;

it('joins segments and percent-encodes each of them', function (): void {
    expect(Path::join('tags', 'V1StGXR8_Z5jdHi6B-myT'))->toBe('tags/V1StGXR8_Z5jdHi6B-myT')
        ->and(Path::join('tags', 'a/b?c=d&e f'))->toBe('tags/a%2Fb%3Fc%3Dd%26e%20f')
        ->and(Path::join('statuspages', 'p', 'assets', 'logo'))->toBe('statuspages/p/assets/logo');
});

it('rejects empty segments and an empty path', function (): void {
    expect(fn(): string => Path::join('tags', ''))->toThrow(InvalidArgumentException::class, 'must not be empty')
        ->and(fn(): string => Path::join())->toThrow(InvalidArgumentException::class, 'at least one segment');
});
