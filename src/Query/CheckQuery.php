<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Query;

use DateTimeImmutable;
use DateTimeInterface;
use LIVCK\Cloud\Enums\CheckResultStatus;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Filters and keyset paging for `GET /v1/services/{id}/checks`.
 *
 *  - `probes`: only checks from these locations (codes from `GET /v1/probes`); an unknown
 *    code simply matches nothing.
 *  - `statuses`: only checks with these results.
 *  - `from` (inclusive) / `to` (exclusive): the window. `from` is clamped to the start of
 *    the available history (at most 90 days, less on a smaller plan, never before the
 *    service was configured).
 *  - `cursor`: the opaque `next_cursor` of the previous page, passed back unchanged.
 *
 * Checks come newest first. Every filter narrows the list; none of them can widen it.
 */
final readonly class CheckQuery
{
    public const int MAX_PER_PAGE = 100;

    /** The server's ceiling on either list filter. */
    public const int MAX_LIST_FILTERS = 50;

    /**
     * @param list<string>|null $probes
     * @param list<CheckResultStatus>|null $statuses
     */
    public function __construct(
        public ?array $probes = null,
        public ?array $statuses = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?int $perPage = null,
        public ?string $cursor = null,
    ) {
        foreach ($probes ?? [] as $probe) {
            if (trim($probe) === '') {
                throw new InvalidArgumentException('A probe code in the probes filter must not be blank.');
            }
        }

        if ($probes !== null && count($probes) > self::MAX_LIST_FILTERS) {
            throw new InvalidArgumentException(sprintf('The probes filter takes at most %d codes.', self::MAX_LIST_FILTERS));
        }

        if ($statuses !== null && count($statuses) > self::MAX_LIST_FILTERS) {
            throw new InvalidArgumentException(sprintf('The statuses filter takes at most %d values.', self::MAX_LIST_FILTERS));
        }

        if ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable && $to <= $from) {
            throw new InvalidArgumentException('The to bound must lie after the from bound.');
        }

        if ($perPage !== null && ($perPage < 1 || $perPage > self::MAX_PER_PAGE)) {
            throw new InvalidArgumentException(sprintf('perPage must be between 1 and %d.', self::MAX_PER_PAGE));
        }

        if ($cursor !== null && trim($cursor) === '') {
            throw new InvalidArgumentException('A cursor must not be blank; pass next_cursor from the previous page back unchanged.');
        }
    }

    public static function make(): self
    {
        return new self();
    }

    /** Only checks from these locations; no code at all lifts the filter. */
    public function withProbes(string ...$codes): self
    {
        return new self($codes === [] ? null : array_values($codes), $this->statuses, $this->from, $this->to, $this->perPage, $this->cursor);
    }

    /** Only checks with these results; no status at all lifts the filter. */
    public function withStatuses(CheckResultStatus ...$statuses): self
    {
        return new self($this->probes, $statuses === [] ? null : array_values($statuses), $this->from, $this->to, $this->perPage, $this->cursor);
    }

    /** Only checks at or after this instant. */
    public function withFrom(DateTimeInterface $from): self
    {
        return new self($this->probes, $this->statuses, DateTimeImmutable::createFromInterface($from), $this->to, $this->perPage, $this->cursor);
    }

    /** Only checks before this instant (exclusive). */
    public function withTo(DateTimeInterface $to): self
    {
        return new self($this->probes, $this->statuses, $this->from, DateTimeImmutable::createFromInterface($to), $this->perPage, $this->cursor);
    }

    public function withPerPage(int $perPage): self
    {
        return new self($this->probes, $this->statuses, $this->from, $this->to, $perPage, $this->cursor);
    }

    /** Continue after the page that handed out this cursor. */
    public function withCursor(string $cursor): self
    {
        return new self($this->probes, $this->statuses, $this->from, $this->to, $this->perPage, $cursor);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'probe' => $this->probes,
            'status' => $this->statuses,
            'from' => $this->from,
            'to' => $this->to,
            'per_page' => $this->perPage,
            'cursor' => $this->cursor,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
