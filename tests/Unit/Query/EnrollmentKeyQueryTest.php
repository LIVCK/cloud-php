<?php

declare(strict_types=1);

use LIVCK\Cloud\Enums\EnrollmentKeyStatus;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Query\EnrollmentKeyQuery;

it('sends only what was set', function (): void {
    expect(EnrollmentKeyQuery::make()->toArray())->toBe([])
        ->and(EnrollmentKeyQuery::make()->withStatuses(EnrollmentKeyStatus::Active)->toArray())->toBe(['status' => [EnrollmentKeyStatus::Active]])
        ->and(EnrollmentKeyQuery::make()->withPerPage(100)->withPage(3)->toArray())->toBe(['page' => 3, 'per_page' => 100])
        ->and((new EnrollmentKeyQuery(perPage: 1))->toArray())->toBe(['per_page' => 1]);
});

it('takes several states, and none lifts the filter', function (): void {
    $query = EnrollmentKeyQuery::make()->withStatuses(EnrollmentKeyStatus::Exhausted, EnrollmentKeyStatus::Expired);

    expect($query->statuses)->toBe([EnrollmentKeyStatus::Exhausted, EnrollmentKeyStatus::Expired])
        ->and($query->withStatuses()->statuses)->toBeNull()
        ->and($query->withStatuses()->toArray())->toBe([]);
});

it('is immutable', function (): void {
    $base = EnrollmentKeyQuery::make()->withPerPage(10);
    $paged = $base->withPage(2);
    $filtered = $paged->withStatuses(EnrollmentKeyStatus::Revoked);

    expect($base->page)->toBeNull()
        ->and($paged->page)->toBe(2)
        ->and($paged->perPage)->toBe(10)
        ->and($paged->statuses)->toBeNull()
        ->and($filtered->statuses)->toBe([EnrollmentKeyStatus::Revoked])
        ->and($filtered->page)->toBe(2);
});

it('refuses out-of-range paging', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'page zero' => [fn(): EnrollmentKeyQuery => EnrollmentKeyQuery::make()->withPage(0), 'page must be 1 or greater'],
    'per page zero' => [fn(): EnrollmentKeyQuery => EnrollmentKeyQuery::make()->withPerPage(0), 'between 1 and 100'],
    'per page above the server cap' => [fn(): EnrollmentKeyQuery => EnrollmentKeyQuery::make()->withPerPage(101), 'between 1 and 100'],
]);
