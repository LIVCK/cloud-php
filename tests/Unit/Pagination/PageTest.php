<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Support\Json;

/**
 * @param list<array<string, mixed>> $items
 * @return Page<string>
 */
function pageOf(array $items, int $current, int $last, ?Closure $fetch = null): Page
{
    $body = Json::encode([
        'data' => $items,
        'links' => ['first' => 'f', 'last' => 'l', 'prev' => null, 'next' => null],
        'meta' => [
            'current_page' => $current,
            'from' => $items === [] ? null : 1,
            'last_page' => $last,
            'links' => [['url' => null, 'label' => '&laquo; Previous', 'active' => false]],
            'path' => 'https://api.livck.cloud/v1/tags',
            'per_page' => 2,
            'to' => $items === [] ? null : count($items),
            'total' => 5,
        ],
    ]);

    return Page::fromResponse(response(200, $body), fn(array $item): string => is_string($item['id']) ? $item['id'] : '', $fetch);
}

it('hydrates items and paging metadata from the list envelope', function (): void {
    $page = pageOf([['id' => 'a'], ['id' => 'b']], 1, 3);

    expect($page->items)->toBe(['a', 'b'])
        ->and($page->currentPage)->toBe(1)
        ->and($page->lastPage)->toBe(3)
        ->and($page->perPage)->toBe(2)
        ->and($page->total)->toBe(5)
        ->and($page->from)->toBe(1)
        ->and($page->to)->toBe(2)
        ->and($page->hasMorePages())->toBeTrue()
        ->and($page->isEmpty())->toBeFalse()
        ->and($page->first())->toBe('a')
        ->and(count($page))->toBe(2)
        ->and(iterator_to_array($page))->toBe(['a', 'b']);
});

it('reads an empty page', function (): void {
    $page = pageOf([], 1, 1);

    expect($page->isEmpty())->toBeTrue()
        ->and($page->first())->toBeNull()
        ->and($page->from)->toBeNull()
        ->and($page->hasMorePages())->toBeFalse()
        ->and($page->nextPage())->toBeNull();
});

it('loads the next page on demand and stops on the last', function (): void {
    $requested = [];
    $fetch = function (int $number) use (&$requested, &$fetch): Page {
        $requested[] = $number;

        return pageOf([['id' => 'p' . $number]], $number, 3, $fetch);
    };

    $first = pageOf([['id' => 'p1']], 1, 3, $fetch);
    $second = $first->nextPage();
    $third = $second?->nextPage();

    expect($requested)->toBe([2, 3])
        ->and($second?->items)->toBe(['p2'])
        ->and($third?->items)->toBe(['p3'])
        ->and($third?->nextPage())->toBeNull();
});

it('walks every page lazily, one request per page as the iteration advances', function (): void {
    $requested = [];
    $fetch = function (int $number) use (&$requested, &$fetch): Page {
        $requested[] = $number;

        return pageOf([['id' => 'p' . $number . 'a'], ['id' => 'p' . $number . 'b']], $number, 3, $fetch);
    };

    $seen = [];

    foreach (pageOf([['id' => 'p1a'], ['id' => 'p1b']], 1, 3, $fetch)->lazy() as $item) {
        $seen[] = $item;

        if ($item === 'p1b') {
            expect($requested)->toBe([]);
        }

        if ($item === 'p2a') {
            expect($requested)->toBe([2]);
        }
    }

    expect($seen)->toBe(['p1a', 'p1b', 'p2a', 'p2b', 'p3a', 'p3b'])
        ->and($requested)->toBe([2, 3]);
});

it('keeps generator keys unique across pages', function (): void {
    $fetch = fn(int $number): Page => pageOf([['id' => 'x']], $number, 2);

    expect(iterator_to_array(pageOf([['id' => 'w']], 1, 2, $fetch)->lazy()))->toBe(['w', 'x']);
});

it('rejects an envelope that is not a page', function (): void {
    expect(fn(): Page => Page::fromResponse(response(200, '{"data":[]}'), fn(array $i): string => ''))
        ->toThrow(UnexpectedResponseException::class, 'meta');

    expect(fn(): Page => Page::fromResponse(response(200, '{"data":[1],"meta":{"current_page":1,"last_page":1,"per_page":1,"total":1}}'), fn(array $i): string => ''))
        ->toThrow(UnexpectedResponseException::class, 'data[0]');
});
