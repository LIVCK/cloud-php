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
 * One page of an offset-paginated list (`data` + `meta.current_page` / `last_page` /
 * `per_page` / `total` / `from` / `to`, the shape of every v1 list except the check
 * history).
 *
 * Iterating a page walks its items only; {@see lazy()} walks every following page as
 * well, one request at a time, so a loop over thousands of items never holds more than
 * one page in memory.
 *
 * @template T
 * @implements IteratorAggregate<int, T>
 */
final readonly class Page implements IteratorAggregate, Countable
{
    /**
     * @param list<T> $items
     * @param (Closure(int): self<T>)|null $fetchPage loads another page by number
     */
    public function __construct(
        public array $items,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
        public ?int $from,
        public ?int $to,
        private ?Closure $fetchPage = null,
    ) {}

    /**
     * @template TItem
     *
     * @param Closure(array<string, mixed>): TItem $mapper hydrates one item
     * @param (Closure(int): self<TItem>)|null $fetchPage loads another page by number
     * @return self<TItem>
     */
    public static function fromResponse(Response $response, Closure $mapper, ?Closure $fetchPage = null): self
    {
        $payload = $response->json();
        $meta = Field::object($payload, 'meta');
        $items = [];

        foreach (Field::objectList($payload, 'data') as $item) {
            $items[] = $mapper($item);
        }

        return new self(
            $items,
            Field::int($meta, 'current_page'),
            Field::int($meta, 'last_page'),
            Field::int($meta, 'per_page'),
            Field::int($meta, 'total'),
            Field::nullableInt($meta, 'from'),
            Field::nullableInt($meta, 'to'),
            $fetchPage,
        );
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * The following page (one request), or null on the last page.
     *
     * @return self<T>|null
     */
    public function nextPage(): ?self
    {
        if (! $this->hasMorePages() || !$this->fetchPage instanceof Closure) {
            return null;
        }

        return ($this->fetchPage)($this->currentPage + 1);
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

            $page = $page->nextPage();

            if (!$page instanceof Page) {
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

    /** Items on this page (not the list's total; see `$total`). */
    public function count(): int
    {
        return count($this->items);
    }
}
