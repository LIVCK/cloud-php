<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Query;

use DateTimeImmutable;
use DateTimeInterface;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Query\Concerns\NormalizesServiceIds;

/**
 * Filters and paging for `GET /v1/maintenances` and `GET /v1/services/{id}/maintenances`.
 *
 *  - `serviceIds`: only windows covering at least one of these services (ids or Service
 *    DTOs, at most 100); the organization-wide list only. Ids the token cannot see drop
 *    out; an EMPTY list is sent and matches nothing (null lifts the filter).
 *  - `statuses`: one or more statuses.
 *  - `from`: only windows that had not ended before this instant (an open-ended or running
 *    window always qualifies); `to`: only windows planned to start before it (exclusive).
 *
 * Windows come newest scheduled start first; archived ones are never listed.
 */
final readonly class MaintenanceQuery
{
    use NormalizesServiceIds;

    public const int MAX_PER_PAGE = 100;

    public const int MAX_SERVICE_IDS = 100;

    /** @var list<string>|null */
    public ?array $serviceIds;

    /**
     * @param array<array-key, Service|string>|null $serviceIds
     * @param list<MaintenanceStatus>|null $statuses
     */
    public function __construct(
        ?array $serviceIds = null,
        public ?array $statuses = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
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
     * Only windows covering at least one of these services. An empty list matches nothing;
     * pass null to lift the filter. Organization-wide list only.
     *
     * @param array<array-key, Service|string>|null $services
     */
    public function withServiceIds(?array $services): self
    {
        return new self($services, $this->statuses, $this->from, $this->to, $this->page, $this->perPage);
    }

    /** Only windows in these statuses; no status at all lifts the filter. */
    public function withStatuses(MaintenanceStatus ...$statuses): self
    {
        return new self($this->serviceIds, $statuses === [] ? null : array_values($statuses), $this->from, $this->to, $this->page, $this->perPage);
    }

    public function withFrom(DateTimeInterface $from): self
    {
        return new self($this->serviceIds, $this->statuses, DateTimeImmutable::createFromInterface($from), $this->to, $this->page, $this->perPage);
    }

    public function withTo(DateTimeInterface $to): self
    {
        return new self($this->serviceIds, $this->statuses, $this->from, DateTimeImmutable::createFromInterface($to), $this->page, $this->perPage);
    }

    public function withPage(int $page): self
    {
        return new self($this->serviceIds, $this->statuses, $this->from, $this->to, $page, $this->perPage);
    }

    public function withPerPage(int $perPage): self
    {
        return new self($this->serviceIds, $this->statuses, $this->from, $this->to, $this->page, $perPage);
    }

    /** Whether the query carries a service scope (which the service-scoped list refuses). */
    public function hasServiceIds(): bool
    {
        return $this->serviceIds !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'service_ids' => $this->serviceIds,
            'status' => $this->statuses,
            'from' => $this->from,
            'to' => $this->to,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
