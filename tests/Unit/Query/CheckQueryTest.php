<?php

declare(strict_types=1);

use LIVCK\Cloud\Enums\CheckResultStatus;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Query\CheckQuery;

it('sends only what was set, with the server\'s parameter names', function (): void {
    $from = new DateTimeImmutable('2026-09-20T10:00:00Z');
    $to = new DateTimeImmutable('2026-09-20T11:00:00Z');

    expect(CheckQuery::make()->toArray())->toBe([])
        ->and(CheckQuery::make()->withProbes('ffm', 'hel')->toArray())->toBe(['probe' => ['ffm', 'hel']])
        ->and(CheckQuery::make()->withStatuses(CheckResultStatus::Down)->toArray())->toBe(['status' => [CheckResultStatus::Down]])
        ->and(CheckQuery::make()->withFrom($from)->withTo($to)->withPerPage(10)->withCursor('abc')->toArray())
        ->toEqual(['from' => $from, 'to' => $to, 'per_page' => 10, 'cursor' => 'abc']);
});

it('lifts a list filter when no member is given', function (): void {
    expect(CheckQuery::make()->withProbes('ffm')->withProbes()->toArray())->toBe([])
        ->and(CheckQuery::make()->withStatuses(CheckResultStatus::Up)->withStatuses()->toArray())->toBe([]);
});

it('converts a mutable bound to an immutable UTC instant', function (): void {
    $bound = new DateTime('2026-09-20 12:00:00', new DateTimeZone('Europe/Berlin'));

    $query = CheckQuery::make()->withFrom($bound);
    $bound->modify('+1 day');

    expect($query->from)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($query->from?->format('c'))->toBe('2026-09-20T12:00:00+02:00');
});

it('is immutable', function (): void {
    $base = CheckQuery::make()->withProbes('ffm');
    $paged = $base->withCursor('c1');

    expect($base->cursor)->toBeNull()
        ->and($paged->cursor)->toBe('c1')
        ->and($paged->probes)->toBe(['ffm']);
});

it('refuses what the server would reject or quietly ignore', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank probe code' => [fn(): CheckQuery => CheckQuery::make()->withProbes('ffm', ' '), 'probe code'],
    'too many probes' => [fn(): CheckQuery => CheckQuery::make()->withProbes(...array_map(static fn(int $i): string => 'p' . $i, range(1, 51))), 'at most 50'],
    'to before from' => [fn(): CheckQuery => CheckQuery::make()->withFrom(new DateTimeImmutable('2026-09-20T11:00:00Z'))->withTo(new DateTimeImmutable('2026-09-20T10:00:00Z')), 'after the from bound'],
    'to equal to from' => [fn(): CheckQuery => CheckQuery::make()->withFrom(new DateTimeImmutable('2026-09-20T10:00:00Z'))->withTo(new DateTimeImmutable('2026-09-20T10:00:00Z')), 'after the from bound'],
    'per page zero' => [fn(): CheckQuery => CheckQuery::make()->withPerPage(0), 'between 1 and 100'],
    'per page above the server cap' => [fn(): CheckQuery => CheckQuery::make()->withPerPage(101), 'between 1 and 100'],
    'blank cursor' => [fn(): CheckQuery => CheckQuery::make()->withCursor(''), 'cursor'],
]);
