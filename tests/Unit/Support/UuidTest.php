<?php

declare(strict_types=1);

use LIVCK\Cloud\Support\Uuid;

it('generates canonical version-4 UUIDs', function (): void {
    $uuid = Uuid::v4();

    expect($uuid)->toHaveLength(36)
        ->and(Uuid::isV4($uuid))->toBeTrue()
        ->and($uuid[14])->toBe('4')
        ->and(in_array($uuid[19], ['8', '9', 'a', 'b'], true))->toBeTrue();
});

it('does not repeat', function (): void {
    $generated = [];

    foreach (range(1, 200) as $ignored) {
        $generated[] = Uuid::v4();
    }

    expect(array_unique($generated))->toHaveCount(200);
});

it('validates the canonical form only', function (): void {
    expect(Uuid::isV4('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue()
        ->and(Uuid::isV4('550e8400-e29b-11d4-a716-446655440000'))->toBeFalse()
        ->and(Uuid::isV4('550E8400-E29B-41D4-A716-446655440000'))->toBeFalse()
        ->and(Uuid::isV4('not-a-uuid'))->toBeFalse();
});
