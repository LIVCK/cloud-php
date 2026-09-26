<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use Closure;
use LIVCK\Cloud\Http\Response;
use LIVCK\Cloud\Pagination\CursorPage;
use LIVCK\Cloud\Pagination\Page;

/**
 * The response envelopes of the v1 API, hydrated through a mapper.
 *
 *  - {@see item()}: one resource under `data` (every show/create/update response);
 *  - {@see bare()}: an object without a wrapper (`GET /v1/me`);
 *  - {@see collection()}: a plain `data` array without paging (statuspage components,
 *    custom domains);
 *  - {@see Page()}: an offset-paginated list (`data` + `links` + `meta.current_page` …);
 *  - {@see CursorPage()}: a keyset-paginated list (`data` + `meta.next_cursor`).
 *
 * `meta.links[]` of a paginated list is rendered for HTML pagers and ignored here.
 */
final class Envelope
{
    /**
     * @template T
     *
     * @param Closure(array<string, mixed>): T $mapper
     * @return T
     */
    public static function item(Response $response, Closure $mapper): mixed
    {
        return $mapper(Field::object($response->json(), 'data'));
    }

    /**
     * @template T
     *
     * @param Closure(array<string, mixed>): T $mapper
     * @return T
     */
    public static function bare(Response $response, Closure $mapper): mixed
    {
        return $mapper($response->json());
    }

    /**
     * @template T
     *
     * @param Closure(array<string, mixed>): T $mapper
     * @return list<T>
     */
    public static function collection(Response $response, Closure $mapper): array
    {
        $items = [];

        foreach (Field::objectList($response->json(), 'data') as $item) {
            $items[] = $mapper($item);
        }

        return $items;
    }

    /**
     * @template T
     *
     * @param Closure(array<string, mixed>): T $mapper
     * @param (Closure(int): Page<T>)|null $fetchPage loads another page by number
     * @return Page<T>
     */
    public static function page(Response $response, Closure $mapper, ?Closure $fetchPage = null): Page
    {
        return Page::fromResponse($response, $mapper, $fetchPage);
    }

    /**
     * @template T
     *
     * @param Closure(array<string, mixed>): T $mapper
     * @param (Closure(string): CursorPage<T>)|null $fetchAfter loads the page after a cursor
     * @return CursorPage<T>
     */
    public static function cursorPage(Response $response, Closure $mapper, ?Closure $fetchAfter = null): CursorPage
    {
        return CursorPage::fromResponse($response, $mapper, $fetchAfter);
    }
}
