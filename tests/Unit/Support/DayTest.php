<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Day;

it('parses and prints YYYY-MM-DD', function (): void {
    $day = Day::fromString('2026-08-01');

    expect($day->year)->toBe(2026)
        ->and($day->month)->toBe(8)
        ->and($day->day)->toBe(1)
        ->and($day->toString())->toBe('2026-08-01')
        ->and((string) $day)->toBe('2026-08-01')
        ->and(json_encode($day))->toBe('"2026-08-01"')
        ->and(Day::of(2026, 8, 1)->equals($day))->toBeTrue();
});

it('rejects anything but a real calendar day', function (string $value): void {
    expect(Day::tryFromString($value))->toBeNull();
    expect(fn(): Day => Day::fromString($value))->toThrow(InvalidArgumentException::class);
})->with(['', '2026-02-30', '2026-13-01', '2026-08-01T00:00:00Z', '1.8.2026']);

it('rejects impossible components', function (): void {
    expect(fn(): Day => Day::of(2026, 2, 30))->toThrow(InvalidArgumentException::class, 'not a calendar day');
});

it('is derived from an instant in a chosen timezone, so midnight does not shift a day', function (): void {
    $instant = new DateTimeImmutable('2026-08-01T22:30:00Z');

    expect(Day::fromDateTime($instant)->toString())->toBe('2026-08-01')
        ->and(Day::fromDateTime($instant, new DateTimeZone('Europe/Berlin'))->toString())->toBe('2026-08-02');
});

it('converts to midnight UTC explicitly', function (): void {
    $start = Day::fromString('2026-08-01')->startOfDayUtc();

    expect($start->format(DATE_ATOM))->toBe('2026-08-01T00:00:00+00:00');
});

it('compares chronologically', function (): void {
    $earlier = Day::fromString('2026-07-31');
    $later = Day::fromString('2026-08-01');

    expect($earlier->isBefore($later))->toBeTrue()
        ->and($later->isAfter($earlier))->toBeTrue()
        ->and($earlier->isAfter($later))->toBeFalse()
        ->and($earlier->equals($later))->toBeFalse();
});
