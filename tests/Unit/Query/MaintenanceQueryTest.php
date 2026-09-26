<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;

it('sends only what was set, with the server\'s parameter names', function (): void {
    $from = new DateTimeImmutable('2026-10-01T00:00:00Z');

    expect(MaintenanceQuery::make()->toArray())->toBe([])
        ->and(MaintenanceQuery::make()->withStatuses(MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress)->toArray())
        ->toBe(['status' => [MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress]])
        ->and(MaintenanceQuery::make()->withFrom($from)->withPage(2)->withPerPage(50)->toArray())->toEqual(['from' => $from, 'page' => 2, 'per_page' => 50])
        ->and(MaintenanceQuery::make()->withStatuses(MaintenanceStatus::Completed)->withStatuses()->toArray())->toBe([]);
});

it('keeps an explicitly empty service scope apart from no scope', function (): void {
    $service = Service::fromArray(ServiceFixtures::payload());

    expect(MaintenanceQuery::make()->withServiceIds([])->toArray())->toBe(['service_ids' => []])
        ->and(MaintenanceQuery::make()->withServiceIds([])->hasServiceIds())->toBeTrue()
        ->and(MaintenanceQuery::make()->withServiceIds(null)->hasServiceIds())->toBeFalse()
        ->and(MaintenanceQuery::make()->withServiceIds([$service, $service])->serviceIds)->toBe([ServiceFixtures::ID]);
});

it('is immutable', function (): void {
    $base = MaintenanceQuery::make()->withStatuses(MaintenanceStatus::Scheduled);
    $paged = $base->withPage(2);

    expect($base->page)->toBeNull()
        ->and($paged->page)->toBe(2)
        ->and($paged->statuses)->toBe([MaintenanceStatus::Scheduled]);
});

it('refuses more than 100 service ids, blank ids, a bad window and out-of-range paging', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'too many ids' => [fn(): MaintenanceQuery => MaintenanceQuery::make()->withServiceIds(array_map(static fn(int $i): string => 's' . $i, range(1, 101))), 'chunks of 100'],
    'blank id' => [fn(): MaintenanceQuery => MaintenanceQuery::make()->withServiceIds([' ']), 'must not be blank'],
    'to before from' => [fn(): MaintenanceQuery => MaintenanceQuery::make()->withFrom(new DateTimeImmutable('2026-10-02T00:00:00Z'))->withTo(new DateTimeImmutable('2026-10-01T00:00:00Z')), 'after the from bound'],
    'page zero' => [fn(): MaintenanceQuery => MaintenanceQuery::make()->withPage(0), 'page must be 1 or greater'],
    'per page above the server cap' => [fn(): MaintenanceQuery => MaintenanceQuery::make()->withPerPage(101), 'between 1 and 100'],
]);
