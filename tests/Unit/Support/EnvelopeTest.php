<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Pagination\CursorPage;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Support\Envelope;

$id = fn(array $item): string => is_string($item['id']) ? $item['id'] : '';

it('hydrates one resource under data', function () use ($id): void {
    expect(Envelope::item(response(200, '{"data":{"id":"a"}}'), $id))->toBe('a');
});

it('hydrates an object without a wrapper, as GET /v1/me answers', function () use ($id): void {
    expect(Envelope::bare(response(200, '{"id":"me","type":"user"}'), $id))->toBe('me');
});

it('hydrates a plain data array, as components and custom domains answer', function () use ($id): void {
    expect(Envelope::collection(response(200, '{"data":[{"id":"a"},{"id":"b"}]}'), $id))->toBe(['a', 'b'])
        ->and(Envelope::collection(response(200, '{"data":[]}'), $id))->toBe([]);
});

it('hydrates offset and keyset pages', function () use ($id): void {
    $page = Envelope::page(response(200, '{"data":[{"id":"a"}],"links":{},"meta":{"current_page":1,"from":1,"last_page":2,"per_page":1,"to":1,"total":2,"path":"x","links":[]}}'), $id);
    $cursor = Envelope::cursorPage(response(200, '{"data":[{"id":"c"}],"meta":{"per_page":50,"next_cursor":"n"},"links":{"next":"u"}}'), $id);

    expect($page)->toBeInstanceOf(Page::class)
        ->and($page->items)->toBe(['a'])
        ->and($page->hasMorePages())->toBeTrue()
        ->and($cursor)->toBeInstanceOf(CursorPage::class)
        ->and($cursor->items)->toBe(['c'])
        ->and($cursor->nextCursor)->toBe('n');
});

it('reports a missing or malformed data key', function () use ($id): void {
    expect(fn(): string => Envelope::item(response(200, '{"id":"a"}'), $id))
        ->toThrow(UnexpectedResponseException::class, 'Field "data": expected object, got null');

    expect(fn(): array => Envelope::collection(response(200, '{"data":{"id":"a"}}'), $id))
        ->toThrow(UnexpectedResponseException::class, 'Field "data": expected array');
});
