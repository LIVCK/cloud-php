<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Query\ServiceQuery;

it('sends only what was set', function (): void {
    expect(ServiceQuery::make()->toArray())->toBe([])
        ->and(ServiceQuery::make()->withTag('kunde:4711')->toArray())->toBe(['tag' => 'kunde:4711'])
        ->and(ServiceQuery::make()->withPage(3)->withPerPage(100)->toArray())->toBe(['page' => 3, 'per_page' => 100])
        ->and((new ServiceQuery(tag: 'critical', perPage: 1))->toArray())->toBe(['tag' => 'critical', 'per_page' => 1]);
});

it('takes a Tag DTO and filters by its label', function (): void {
    $tag = Tag::fromArray(tagPayload());

    expect(ServiceQuery::make()->withTag($tag)->toArray())->toBe(['tag' => 'kunde:4711']);
});

it('is immutable', function (): void {
    $base = ServiceQuery::make()->withTag('kunde:4711');
    $paged = $base->withPage(2);

    expect($base->page)->toBeNull()
        ->and($paged->page)->toBe(2)
        ->and($paged->tag)->toBe('kunde:4711');
});

it('refuses a blank tag, which would match nothing on the server, and out-of-range paging', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank tag' => [fn(): ServiceQuery => ServiceQuery::make()->withTag(' '), 'tag filter must not be blank'],
    'page zero' => [fn(): ServiceQuery => ServiceQuery::make()->withPage(0), 'page must be 1 or greater'],
    'per page zero' => [fn(): ServiceQuery => ServiceQuery::make()->withPerPage(0), 'between 1 and 100'],
    'per page above the server cap' => [fn(): ServiceQuery => ServiceQuery::make()->withPerPage(101), 'between 1 and 100'],
]);
