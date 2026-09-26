<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Query;

use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Filters and paging for `GET /v1/services`.
 *
 *  - `tag`: exactly one tag label in its canonical form (`customer:4711`, also `customer=4711`)
 *    or a bare key for the tag without a value. A tag nobody carries yields an empty page,
 *    never an error. Only one tag per request; intersect further on the client.
 *
 * Services come newest first.
 */
final readonly class ServiceQuery
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        public ?string $tag = null,
        public ?int $page = null,
        public ?int $perPage = null,
    ) {
        if ($tag !== null && trim($tag) === '') {
            throw new InvalidArgumentException('The tag filter must not be blank; the server would answer with an empty list.');
        }

        if ($page !== null && $page < 1) {
            throw new InvalidArgumentException('page must be 1 or greater.');
        }

        if ($perPage !== null && ($perPage < 1 || $perPage > self::MAX_PER_PAGE)) {
            throw new InvalidArgumentException(sprintf('perPage must be between 1 and %d.', self::MAX_PER_PAGE));
        }
    }

    public static function make(): self
    {
        return new self();
    }

    /** Only services carrying this tag: a label (`customer:4711`) or a Tag DTO. */
    public function withTag(Tag|string $tag): self
    {
        return new self($tag instanceof Tag ? $tag->label : $tag, $this->page, $this->perPage);
    }

    public function withPage(int $page): self
    {
        return new self($this->tag, $page, $this->perPage);
    }

    public function withPerPage(int $perPage): self
    {
        return new self($this->tag, $this->page, $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'tag' => $this->tag,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
