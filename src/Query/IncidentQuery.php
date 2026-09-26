<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Query;

use DateTimeImmutable;
use DateTimeInterface;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Enums\IncidentKind;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Query\Concerns\NormalizesServiceIds;

/**
 * Filters and paging for `GET /v1/incidents`.
 *
 *  - `serviceIds`: only incidents touching at least one of these services (ids or Service
 *    DTOs, at most 100). Ids the token cannot see drop out; an EMPTY list is sent and
 *    matches nothing (null lifts the filter). Never confuse the two.
 *  - `resolved`: true for resolved incidents only, false for open ones only.
 *  - `published`: true for published incidents only, false for internal ones only.
 *  - `from` / `to`: incidents whose lifetime overlaps the window; open incidents always
 *    match `from`, `to` is exclusive and must lie after `from`.
 *  - `kind`: one incident kind.
 *
 * Incidents come newest first (by `started_at`).
 */
final readonly class IncidentQuery
{
    use NormalizesServiceIds;

    public const int MAX_PER_PAGE = 100;

    public const int MAX_SERVICE_IDS = 100;

    /** @var list<string>|null */
    public ?array $serviceIds;

    /**
     * @param array<array-key, Service|string>|null $serviceIds
     */
    public function __construct(
        ?array $serviceIds = null,
        public ?bool $resolved = null,
        public ?bool $published = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?IncidentKind $kind = null,
        public ?int $page = null,
        public ?int $perPage = null,
    ) {
        $this->serviceIds = $serviceIds === null ? null : self::serviceIdsOf($serviceIds, self::MAX_SERVICE_IDS);

        if ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable && $to <= $from) {
            throw new InvalidArgumentException('The to bound must lie after the from bound.');
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

    /**
     * Only incidents touching at least one of these services. An empty list matches
     * nothing; pass null to lift the filter.
     *
     * @param array<array-key, Service|string>|null $services
     */
    public function withServiceIds(?array $services): self
    {
        return new self($services, $this->resolved, $this->published, $this->from, $this->to, $this->kind, $this->page, $this->perPage);
    }

    public function withResolved(bool $resolved = true): self
    {
        return new self($this->serviceIds, $resolved, $this->published, $this->from, $this->to, $this->kind, $this->page, $this->perPage);
    }

    public function withPublished(bool $published = true): self
    {
        return new self($this->serviceIds, $this->resolved, $published, $this->from, $this->to, $this->kind, $this->page, $this->perPage);
    }

    public function withFrom(DateTimeInterface $from): self
    {
        return new self($this->serviceIds, $this->resolved, $this->published, DateTimeImmutable::createFromInterface($from), $this->to, $this->kind, $this->page, $this->perPage);
    }

    public function withTo(DateTimeInterface $to): self
    {
        return new self($this->serviceIds, $this->resolved, $this->published, $this->from, DateTimeImmutable::createFromInterface($to), $this->kind, $this->page, $this->perPage);
    }

    public function withKind(IncidentKind $kind): self
    {
        return new self($this->serviceIds, $this->resolved, $this->published, $this->from, $this->to, $kind, $this->page, $this->perPage);
    }

    public function withPage(int $page): self
    {
        return new self($this->serviceIds, $this->resolved, $this->published, $this->from, $this->to, $this->kind, $page, $this->perPage);
    }

    public function withPerPage(int $perPage): self
    {
        return new self($this->serviceIds, $this->resolved, $this->published, $this->from, $this->to, $this->kind, $this->page, $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'service_ids' => $this->serviceIds,
            'resolved' => $this->resolved,
            'is_published' => $this->published,
            'from' => $this->from,
            'to' => $this->to,
            'kind' => $this->kind,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
