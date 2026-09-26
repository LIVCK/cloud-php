<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Query\StatuspageQuery;

it('sends only what was set', function (): void {
    expect(StatuspageQuery::make()->toArray())->toBe([])
        ->and(StatuspageQuery::make()->withPage(2)->toArray())->toBe(['page' => 2])
        ->and(StatuspageQuery::make()->withPerPage(100)->withPage(3)->toArray())->toBe(['page' => 3, 'per_page' => 100])
        ->and(StatuspageQuery::make()->withSlug('acme-4711')->withPerPage(1)->toArray())->toBe(['slug' => 'acme-4711', 'per_page' => 1])
        ->and((new StatuspageQuery(perPage: 1))->toArray())->toBe(['per_page' => 1]);
});

it('is immutable', function (): void {
    $base = StatuspageQuery::make()->withPerPage(10);
    $paged = $base->withPage(2);
    $filtered = $paged->withSlug('acme-4711');

    expect($base->page)->toBeNull()
        ->and($paged->page)->toBe(2)
        ->and($paged->perPage)->toBe(10)
        ->and($paged->slug)->toBeNull()
        ->and($filtered->slug)->toBe('acme-4711')
        ->and($filtered->page)->toBe(2);
});

it('refuses a blank slug filter', function (string $slug): void {
    expect(fn(): StatuspageQuery => StatuspageQuery::make()->withSlug($slug))->toThrow(InvalidArgumentException::class, 'must not be blank');
})->with(['', '  ']);

it('refuses out-of-range paging', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'page zero' => [fn(): StatuspageQuery => StatuspageQuery::make()->withPage(0), 'page must be 1 or greater'],
    'per page zero' => [fn(): StatuspageQuery => StatuspageQuery::make()->withPerPage(0), 'between 1 and 100'],
    'per page above the server cap' => [fn(): StatuspageQuery => StatuspageQuery::make()->withPerPage(101), 'between 1 and 100'],
]);
