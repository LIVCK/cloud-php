<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Payloads\UpdateTag;

it('collects only the fields that were set', function (): void {
    expect(UpdateTag::make()->isEmpty())->toBeTrue()
        ->and(UpdateTag::make()->toArray())->toBe([])
        ->and(UpdateTag::make()->withColor('#22C55E')->toArray())->toBe(['color' => '#22C55E'])
        ->and(UpdateTag::make()->withKey('kunde')->withValue('4712')->toArray())->toBe(['key' => 'kunde', 'value' => '4712'])
        ->and(UpdateTag::make()->withoutValue()->toArray())->toBe(['value' => null])
        ->and(UpdateTag::make()->withValue('a')->withValue('b')->toArray())->toBe(['value' => 'b']);
});

it('is immutable', function (): void {
    $base = UpdateTag::make()->withKey('kunde');
    $colored = $base->withColor('#000000');

    expect($base->toArray())->toBe(['key' => 'kunde'])
        ->and($colored->toArray())->toBe(['key' => 'kunde', 'color' => '#000000']);
});

it('rejects a blank key and a malformed color', function (): void {
    expect(fn(): UpdateTag => UpdateTag::make()->withKey(' '))->toThrow(InvalidArgumentException::class, 'blank')
        ->and(fn(): UpdateTag => UpdateTag::make()->withColor('red'))->toThrow(InvalidArgumentException::class, 'hex triplet')
        ->and(fn(): UpdateTag => UpdateTag::make()->withColor('#fff'))->toThrow(InvalidArgumentException::class, 'hex triplet');
});
