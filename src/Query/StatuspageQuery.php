<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Query;

use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Filter and paging for `GET /v1/statuspages`.
 *
 *  - `slug`: exactly the page with this slug (the part before `.statuspage.de`), matched
 *    within the organization. An unknown slug yields an empty page, never an error.
 *
 * Pages come newest first, 15 per page unless `perPage` says otherwise. List entries carry
 * `componentsCount` but not the components themselves; `get()` returns those.
 */
final readonly class StatuspageQuery
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        public ?string $slug = null,
        public ?int $page = null,
        public ?int $perPage = null,
    ) {
        if ($slug !== null && trim($slug) === '') {
            throw new InvalidArgumentException('The slug filter must not be blank; the server would answer with an empty list.');
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

    public function withSlug(string $slug): self
    {
        return new self($slug, $this->page, $this->perPage);
    }

    public function withPage(int $page): self
    {
        return new self($this->slug, $page, $this->perPage);
    }

    public function withPerPage(int $perPage): self
    {
        return new self($this->slug, $this->page, $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'slug' => $this->slug,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
