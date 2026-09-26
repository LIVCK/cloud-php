<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Pagination\CursorPage;
use LIVCK\Cloud\Support\Json;

/**
 * @param list<array<string, mixed>> $items
 * @return CursorPage<string>
 */
function cursorPageOf(array $items, ?string $next, ?Closure $fetch = null): CursorPage
{
    $body = Json::encode([
        'data' => $items,
        'meta' => ['per_page' => 2, 'next_cursor' => $next],
        'links' => ['next' => $next === null ? null : 'https://api.livck.cloud/v1/services/s/checks?cursor=' . $next],
    ]);

    return CursorPage::fromResponse(response(200, $body), fn(array $item): string => is_string($item['id']) ? $item['id'] : '', $fetch);
}

it('hydrates items and the cursor from the keyset envelope', function (): void {
    $page = cursorPageOf([['id' => 'a'], ['id' => 'b']], 'eyJ0IjoiLi4uIn0');

    expect($page->items)->toBe(['a', 'b'])
        ->and($page->perPage)->toBe(2)
        ->and($page->nextCursor)->toBe('eyJ0IjoiLi4uIn0')
        ->and($page->hasMore())->toBeTrue()
        ->and($page->first())->toBe('a')
        ->and(count($page))->toBe(2)
        ->and(iterator_to_array($page))->toBe(['a', 'b']);
});

it('says so on the last page instead of sending the client on to an empty one', function (): void {
    $page = cursorPageOf([['id' => 'z']], null);

    expect($page->hasMore())->toBeFalse()
        ->and($page->next())->toBeNull()
        ->and(cursorPageOf([], null)->isEmpty())->toBeTrue();
});

it('passes the cursor back unchanged to load the next page', function (): void {
    $cursors = [];
    $fetch = function (string $cursor) use (&$cursors, &$fetch): CursorPage {
        $cursors[] = $cursor;

        return cursorPageOf([['id' => 'after-' . $cursor]], $cursor === 'c1' ? 'c2' : null, $fetch);
    };

    $first = cursorPageOf([['id' => 'start']], 'c1', $fetch);
    $second = $first->next();
    $third = $second?->next();

    expect($cursors)->toBe(['c1', 'c2'])
        ->and($second?->items)->toBe(['after-c1'])
        ->and($third?->items)->toBe(['after-c2'])
        ->and($third?->next())->toBeNull();
});

it('walks every page lazily', function (): void {
    $cursors = [];
    $fetch = function (string $cursor) use (&$cursors, &$fetch): CursorPage {
        $cursors[] = $cursor;

        return cursorPageOf([['id' => $cursor]], $cursor === 'c1' ? 'c2' : null, $fetch);
    };

    $seen = [];

    foreach (cursorPageOf([['id' => 'start']], 'c1', $fetch)->lazy() as $item) {
        if ($item === 'start') {
            expect($cursors)->toBe([]);
        }

        $seen[] = $item;
    }

    expect($seen)->toBe(['start', 'c1', 'c2'])
        ->and($cursors)->toBe(['c1', 'c2']);
});

it('rejects an envelope without meta', function (): void {
    expect(fn(): CursorPage => CursorPage::fromResponse(response(200, '{"data":[]}'), fn(array $i): string => ''))
        ->toThrow(UnexpectedResponseException::class, 'meta');
});
