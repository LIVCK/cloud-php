<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Query;

use DateTimeImmutable;
use DateTimeInterface;
use LIVCK\Cloud\Enums\IncidentKind;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Filters and paging for `GET /v1/services/{id}/incidents`: the filters of
 * {@see IncidentQuery} without `serviceIds`, because the service is the route.
 */
final readonly class ServiceIncidentQuery
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        public ?bool $resolved = null,
        public ?bool $published = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?IncidentKind $kind = null,
        public ?int $page = null,
        public ?int $perPage = null,
    ) {
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

    public function withResolved(bool $resolved = true): self
    {
        return new self($resolved, $this->published, $this->from, $this->to, $this->kind, $this->page, $this->perPage);
    }

    public function withPublished(bool $published = true): self
    {
        return new self($this->resolved, $published, $this->from, $this->to, $this->kind, $this->page, $this->perPage);
    }

    public function withFrom(DateTimeInterface $from): self
    {
        return new self($this->resolved, $this->published, DateTimeImmutable::createFromInterface($from), $this->to, $this->kind, $this->page, $this->perPage);
    }

    public function withTo(DateTimeInterface $to): self
    {
        return new self($this->resolved, $this->published, $this->from, DateTimeImmutable::createFromInterface($to), $this->kind, $this->page, $this->perPage);
    }

    public function withKind(IncidentKind $kind): self
    {
        return new self($this->resolved, $this->published, $this->from, $this->to, $kind, $this->page, $this->perPage);
    }

    public function withPage(int $page): self
    {
        return new self($this->resolved, $this->published, $this->from, $this->to, $this->kind, $page, $this->perPage);
    }

    public function withPerPage(int $perPage): self
    {
        return new self($this->resolved, $this->published, $this->from, $this->to, $this->kind, $this->page, $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
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
