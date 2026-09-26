<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Query\TagQuery;

it('sends only what was set', function (): void {
    expect(TagQuery::make()->toArray())->toBe([])
        ->and(TagQuery::make()->withLabel('kunde:4711')->toArray())->toBe(['label' => 'kunde:4711'])
        ->and(TagQuery::make()->withKey('kunde')->withPage(3)->withPerPage(100)->toArray())->toBe(['key' => 'kunde', 'page' => 3, 'per_page' => 100])
        ->and((new TagQuery(label: 'critical', perPage: 1))->toArray())->toBe(['label' => 'critical', 'per_page' => 1]);
});

it('is immutable', function (): void {
    $base = TagQuery::make()->withKey('kunde');
    $paged = $base->withPage(2);

    expect($base->page)->toBeNull()
        ->and($paged->page)->toBe(2)
        ->and($paged->key)->toBe('kunde');
});

it('refuses blank filters, which would match nothing on the server, and out-of-range paging', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank label' => [fn(): TagQuery => TagQuery::make()->withLabel(' '), 'label filter must not be blank'],
    'blank key' => [fn(): TagQuery => TagQuery::make()->withKey(''), 'key filter must not be blank'],
    'page zero' => [fn(): TagQuery => TagQuery::make()->withPage(0), 'page must be 1 or greater'],
    'per page zero' => [fn(): TagQuery => TagQuery::make()->withPerPage(0), 'between 1 and 100'],
    'per page above the server cap' => [fn(): TagQuery => TagQuery::make()->withPerPage(101), 'between 1 and 100'],
]);
