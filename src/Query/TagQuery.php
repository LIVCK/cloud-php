<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Query;

use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Filters and paging for `GET /v1/tags`.
 *
 *  - `label`: exactly one tag in its canonical form (`customer:4711`, also `customer=4711`),
 *    or a bare key for the tag WITHOUT a value. The key is case-insensitive, the value
 *    must match exactly. An unknown label yields an empty page, never an error.
 *  - `key`: every tag under a key, whatever its value (the valueless one included).
 *  - both given: both must match.
 *
 * Tags come sorted by their manual order, then key, then value.
 */
final readonly class TagQuery
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        public ?string $label = null,
        public ?string $key = null,
        public ?int $page = null,
        public ?int $perPage = null,
    ) {
        if ($label !== null && trim($label) === '') {
            throw new InvalidArgumentException('The label filter must not be blank; the server would answer with an empty list.');
        }

        if ($key !== null && trim($key) === '') {
            throw new InvalidArgumentException('The key filter must not be blank; the server would answer with an empty list.');
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

    public function withLabel(string $label): self
    {
        return new self($label, $this->key, $this->page, $this->perPage);
    }

    public function withKey(string $key): self
    {
        return new self($this->label, $key, $this->page, $this->perPage);
    }

    public function withPage(int $page): self
    {
        return new self($this->label, $this->key, $page, $this->perPage);
    }

    public function withPerPage(int $perPage): self
    {
        return new self($this->label, $this->key, $this->page, $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'label' => $this->label,
            'key' => $this->key,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
