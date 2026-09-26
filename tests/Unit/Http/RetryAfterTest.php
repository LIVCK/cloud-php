<?php

declare(strict_types=1);

use LIVCK\Cloud\Http\RetryAfter;

$now = new DateTimeImmutable('1994-11-06 08:49:00', new DateTimeZone('UTC'));

it('reads delta-seconds', function () use ($now): void {
    expect(RetryAfter::seconds('30', $now))->toBe(30)
        ->and(RetryAfter::seconds(' 1 ', $now))->toBe(1)
        ->and(RetryAfter::seconds('0', $now))->toBe(0);
});

it('reads every HTTP-date form relative to now', function (string $value) use ($now): void {
    expect(RetryAfter::seconds($value, $now))->toBe(37);
})->with([
    'IMF-fixdate' => 'Sun, 06 Nov 1994 08:49:37 GMT',
    'RFC 850' => 'Sunday, 06-Nov-94 08:49:37 GMT',
    'asctime' => 'Sun Nov  6 08:49:37 1994',
]);

it('never goes negative for a date in the past', function () use ($now): void {
    expect(RetryAfter::seconds('Sun, 06 Nov 1994 08:48:00 GMT', $now))->toBe(0);
});

it('yields null for anything else', function (string $value) use ($now): void {
    expect(RetryAfter::seconds($value, $now))->toBeNull();
})->with(['', 'soon', '-5', '1.5', 'Sun, 06 Nov 1994']);
