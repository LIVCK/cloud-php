<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Enums\IncidentKind;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Query\IncidentQuery;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;

it('sends only what was set, with the server\'s parameter names', function (): void {
    $from = new DateTimeImmutable('2026-09-01T00:00:00Z');

    expect(IncidentQuery::make()->toArray())->toBe([])
        ->and(IncidentQuery::make()->withResolved()->withPublished(false)->toArray())->toBe(['resolved' => true, 'is_published' => false])
        ->and(IncidentQuery::make()->withFrom($from)->withKind(IncidentKind::Notice)->withPage(2)->withPerPage(50)->toArray())
        ->toEqual(['from' => $from, 'kind' => IncidentKind::Notice, 'page' => 2, 'per_page' => 50]);
});

it('keeps an explicitly empty service scope apart from no scope', function (): void {
    expect(IncidentQuery::make()->withServiceIds([])->toArray())->toBe(['service_ids' => []])
        ->and(IncidentQuery::make()->withServiceIds([])->serviceIds)->toBe([])
        ->and(IncidentQuery::make()->withServiceIds(null)->toArray())->toBe([])
        ->and(IncidentQuery::make()->serviceIds)->toBeNull();
});

it('takes ids and Service DTOs alike, deduplicated', function (): void {
    $service = Service::fromArray(ServiceFixtures::payload());

    expect(IncidentQuery::make()->withServiceIds([$service, ServiceFixtures::ID, 'other'])->serviceIds)->toBe([ServiceFixtures::ID, 'other']);
});

it('is immutable', function (): void {
    $base = IncidentQuery::make()->withResolved();
    $paged = $base->withPage(2);

    expect($base->page)->toBeNull()
        ->and($paged->page)->toBe(2)
        ->and($paged->resolved)->toBeTrue();
});

it('refuses more than 100 service ids and tells the caller to chunk', function (): void {
    $ids = array_map(static fn(int $i): string => 'service-' . $i, range(1, 101));

    expect(fn(): IncidentQuery => IncidentQuery::make()->withServiceIds($ids))->toThrow(InvalidArgumentException::class, 'chunks of 100');
});

it('refuses blank ids, a window that does not end after it starts, and out-of-range paging', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank id' => [fn(): IncidentQuery => IncidentQuery::make()->withServiceIds(['abc', '']), 'must not be blank'],
    'to before from' => [fn(): IncidentQuery => IncidentQuery::make()->withFrom(new DateTimeImmutable('2026-09-20T00:00:00Z'))->withTo(new DateTimeImmutable('2026-09-10T00:00:00Z')), 'after the from bound'],
    'page zero' => [fn(): IncidentQuery => IncidentQuery::make()->withPage(0), 'page must be 1 or greater'],
    'per page above the server cap' => [fn(): IncidentQuery => IncidentQuery::make()->withPerPage(101), 'between 1 and 100'],
]);
