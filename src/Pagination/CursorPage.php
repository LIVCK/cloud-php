<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Pagination;

use ArrayIterator;
use Closure;
use Countable;
use Generator;
use IteratorAggregate;
use LIVCK\Cloud\Http\Response;
use LIVCK\Cloud\Support\Field;

/**
 * One page of a keyset-paginated list (`data` + `meta.next_cursor` / `per_page`, the
 * shape of `GET /v1/services/{id}/checks`).
 *
 * The cursor is opaque: the position of the last row received, to be passed back
 * unchanged as `?cursor=`. It is null once no further rows exist, so the last page says
 * so instead of sending a client on to an empty one.
 *
 * @template T
 * @implements IteratorAggregate<int, T>
 */
final readonly class CursorPage implements IteratorAggregate, Countable
{
    /**
     * @param list<T> $items
     * @param (Closure(string): self<T>)|null $fetchAfter loads the page after a cursor
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public int $perPage,
        private ?Closure $fetchAfter = null,
    ) {}

    /**
     * @template TItem
     *
     * @param Closure(array<string, mixed>): TItem $mapper hydrates one item
     * @param (Closure(string): self<TItem>)|null $fetchAfter loads the page after a cursor
     * @return self<TItem>
     */
    public static function fromResponse(Response $response, Closure $mapper, ?Closure $fetchAfter = null): self
    {
        $payload = $response->json();
        $meta = Field::object($payload, 'meta');
        $items = [];

        foreach (Field::objectList($payload, 'data') as $item) {
            $items[] = $mapper($item);
        }

        return new self(
            $items,
            Field::nullableString($meta, 'next_cursor'),
            Field::int($meta, 'per_page'),
            $fetchAfter,
        );
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    /**
     * The following page (one request), or null when this was the last.
     *
     * @return self<T>|null
     */
    public function next(): ?self
    {
        if ($this->nextCursor === null || !$this->fetchAfter instanceof Closure) {
            return null;
        }

        return ($this->fetchAfter)($this->nextCursor);
    }

    /**
     * Every item of this and all following pages, fetched as the iteration advances.
     *
     * @return Generator<int, T>
     */
    public function lazy(): Generator
    {
        $page = $this;

        while (true) {
            foreach ($page->items as $item) {
                yield $item;
            }

            $page = $page->next();

            if (!$page instanceof CursorPage) {
                return;
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return T|null
     */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    /**
     * @return ArrayIterator<int, T>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
