<?php

declare(strict_types=1);

use LIVCK\Cloud\Support\KeepSecret;

it('is the server\'s write-only sentinel, verbatim', function (): void {
    expect(KeepSecret::SENTINEL)->toBe('__LIVCK_KEEP_UNCHANGED__')
        ->and((string) KeepSecret::keep())->toBe('__LIVCK_KEEP_UNCHANGED__')
        ->and(json_encode(['auth' => ['token' => KeepSecret::keep()]]))->toBe('{"auth":{"token":"__LIVCK_KEEP_UNCHANGED__"}}');
});

it('recognises the sentinel in values read back from the API', function (): void {
    expect(KeepSecret::isSentinel('__LIVCK_KEEP_UNCHANGED__'))->toBeTrue()
        ->and(KeepSecret::isSentinel(KeepSecret::keep()))->toBeTrue()
        ->and(KeepSecret::isSentinel('sonar:abc'))->toBeFalse()
        ->and(KeepSecret::isSentinel(null))->toBeFalse();
});
