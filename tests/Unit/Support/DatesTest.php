<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Dates;

it('parses every instant form the API writes into UTC', function (string $value, string $expected): void {
    $parsed = Dates::parse($value);

    expect($parsed)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($parsed?->getTimezone()->getName())->toBe('UTC')
        ->and($parsed?->format('Y-m-d H:i:s.u'))->toBe($expected);
})->with([
    'offset +00:00 (most resources)' => ['2026-08-01T12:00:00+00:00', '2026-08-01 12:00:00.000000'],
    '.000Z (check results)' => ['2026-08-01T12:00:00.000Z', '2026-08-01 12:00:00.000000'],
    'Z without fraction (response times)' => ['2026-08-01T12:00:00Z', '2026-08-01 12:00:00.000000'],
    'bare date (uptime days) as midnight UTC' => ['2026-08-01', '2026-08-01 00:00:00.000000'],
    'positive offset is converted' => ['2026-08-01T14:00:00+02:00', '2026-08-01 12:00:00.000000'],
    'negative offset without colon' => ['2026-08-01T07:00:00-0500', '2026-08-01 12:00:00.000000'],
    'hour-only offset' => ['2026-08-01T13:00:00+01', '2026-08-01 12:00:00.000000'],
    'fraction is kept' => ['2026-08-01T12:00:00.5Z', '2026-08-01 12:00:00.500000'],
    'nine-digit fraction is truncated to microseconds' => ['2026-08-01T12:00:00.123456789Z', '2026-08-01 12:00:00.123456'],
    'space separator' => ['2026-08-01 12:00:00', '2026-08-01 12:00:00.000000'],
    'no seconds' => ['2026-08-01T12:00Z', '2026-08-01 12:00:00.000000'],
    'no offset means UTC' => ['2026-08-01T12:00:00', '2026-08-01 12:00:00.000000'],
    'lowercase markers' => ['2026-08-01t12:00:00z', '2026-08-01 12:00:00.000000'],
]);

it('passes null through', function (): void {
    expect(Dates::parse(null))->toBeNull();
});

it('rejects what is not an ISO 8601 instant', function (string $value): void {
    expect(Dates::tryParse($value))->toBeNull();
    expect(fn(): ?DateTimeImmutable => Dates::parse($value))->toThrow(InvalidArgumentException::class, 'ISO 8601');
})->with([
    'empty' => '',
    'unix timestamp' => '@1700000000',
    'relative' => 'yesterday',
    'february 30th' => '2026-02-30',
    'hour 24' => '2026-08-01T24:00:00Z',
    'trailing newline' => "2026-08-01T12:00:00Z\n",
    'rfc 2822' => 'Sat, 01 Aug 2026 12:00:00 +0000',
    'five-digit year' => '32767-01-01',
]);

it('formats in UTC with Z and a fraction only when it carries information', function (): void {
    $berlin = new DateTimeImmutable('2026-08-01 14:30:00', new DateTimeZone('Europe/Berlin'));
    $micro = new DateTimeImmutable('2026-08-01 12:00:00.250000', new DateTimeZone('UTC'));
    $mutable = new DateTime('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    expect(Dates::format($berlin))->toBe('2026-08-01T12:30:00Z')
        ->and(Dates::format($micro))->toBe('2026-08-01T12:00:00.25Z')
        ->and(Dates::format($mutable))->toBe('2026-08-01T12:00:00Z');
});

it('round-trips through parse and format', function (): void {
    expect(Dates::parse('2026-08-01T00:00:00Z')?->getTimestamp())->toBe(1785542400)
        ->and(Dates::format(Dates::parse('2026-08-01T00:00:00.000Z') ?? new DateTimeImmutable()))->toBe('2026-08-01T00:00:00Z');
});
