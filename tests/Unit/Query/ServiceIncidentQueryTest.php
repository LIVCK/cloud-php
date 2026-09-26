<?php

declare(strict_types=1);

use LIVCK\Cloud\Enums\IncidentKind;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Query\ServiceIncidentQuery;

it('sends only what was set, with the server\'s parameter names', function (): void {
    $from = new DateTimeImmutable('2026-09-01T00:00:00Z');
    $to = new DateTimeImmutable('2026-10-01T00:00:00Z');

    expect(ServiceIncidentQuery::make()->toArray())->toBe([])
        ->and(ServiceIncidentQuery::make()->withResolved(false)->withPublished()->toArray())->toBe(['resolved' => false, 'is_published' => true])
        ->and(ServiceIncidentQuery::make()->withFrom($from)->withTo($to)->withKind(IncidentKind::AgentLiveness)->withPage(2)->withPerPage(15)->toArray())
        ->toEqual(['from' => $from, 'to' => $to, 'kind' => IncidentKind::AgentLiveness, 'page' => 2, 'per_page' => 15]);
});

it('is immutable', function (): void {
    $base = ServiceIncidentQuery::make()->withKind(IncidentKind::Standard);
    $paged = $base->withPage(2);

    expect($base->page)->toBeNull()
        ->and($paged->page)->toBe(2)
        ->and($paged->kind)->toBe(IncidentKind::Standard);
});

it('refuses a window that does not end after it starts and out-of-range paging', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'to equal to from' => [fn(): ServiceIncidentQuery => ServiceIncidentQuery::make()->withFrom(new DateTimeImmutable('2026-09-20T00:00:00Z'))->withTo(new DateTimeImmutable('2026-09-20T00:00:00Z')), 'after the from bound'],
    'page zero' => [fn(): ServiceIncidentQuery => ServiceIncidentQuery::make()->withPage(0), 'page must be 1 or greater'],
    'per page zero' => [fn(): ServiceIncidentQuery => ServiceIncidentQuery::make()->withPerPage(0), 'between 1 and 100'],
]);
