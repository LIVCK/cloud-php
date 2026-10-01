<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Query;

use LIVCK\Cloud\Enums\EnrollmentKeyStatus;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Filter and paging for `GET /v1/enrollment-keys`.
 *
 *  - `statuses`: only keys in one of these states (`Active` for those that can still enroll a
 *    server).
 *
 * Keys come newest first, 50 per page unless `perPage` says otherwise. Dead keys (revoked,
 * expired or exhausted) are deleted 30 days after they were created.
 */
final readonly class EnrollmentKeyQuery
{
    public const int MAX_PER_PAGE = 100;

    /**
     * @param list<EnrollmentKeyStatus>|null $statuses
     */
    public function __construct(
        public ?array $statuses = null,
        public ?int $page = null,
        public ?int $perPage = null,
    ) {
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

    /** Only keys in these states; no status at all lifts the filter. */
    public function withStatuses(EnrollmentKeyStatus ...$statuses): self
    {
        return new self($statuses === [] ? null : array_values($statuses), $this->page, $this->perPage);
    }

    public function withPage(int $page): self
    {
        return new self($this->statuses, $page, $this->perPage);
    }

    public function withPerPage(int $perPage): self
    {
        return new self($this->statuses, $this->page, $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->statuses,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
