<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Data\Concerns\ReadsStringMaps;
use LIVCK\Cloud\Enums\IncidentKind;
use LIVCK\Cloud\Enums\IncidentSeverity;
use LIVCK\Cloud\Enums\IncidentStatus;
use LIVCK\Cloud\Support\Field;

/**
 * An incident (`/v1/incidents`, `/v1/services/{id}/incidents`).
 *
 * `title` is resolved to the client's locale by the server; `titleTranslations` carries
 * every translation. `updates` are included by `get()` only, `serviceImpact` only when the
 * incident was listed through a service.
 */
final readonly class Incident
{
    use ReadsStringMaps;

    /**
     * @param array<string, string>|null $titleTranslations locale => title; null when the title has no translations
     * @param bool $isPublished false for an internal incident that no statuspage shows
     * @param DateTimeImmutable|null $resolvedAt null while the incident is open
     * @param list<Attachment> $attachments
     * @param list<Reference>|null $services the affected services; null when the endpoint did not include them
     * @param list<IncidentUpdate>|null $updates the public timeline; included by `get()` only
     * @param IncidentServiceImpact|null $serviceImpact what the incident meant for the service it was listed through
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?array $titleTranslations,
        public IncidentStatus $status,
        public IncidentKind $kind,
        public IncidentSeverity $severity,
        public bool $isPublished,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $resolvedAt,
        public DateTimeImmutable $createdAt,
        public array $attachments,
        public ?array $services,
        public ?array $updates,
        public ?IncidentServiceImpact $serviceImpact,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $impact = Field::nullableObject($data, 'service_impact');

        return new self(
            Field::string($data, 'id'),
            Field::string($data, 'title'),
            self::nullableStringMap($data, 'title_translations'),
            IncidentStatus::fromApi(Field::string($data, 'status')),
            IncidentKind::fromApi(Field::string($data, 'kind')),
            IncidentSeverity::fromApi(Field::string($data, 'severity')),
            Field::bool($data, 'is_published'),
            Field::instant($data, 'started_at'),
            Field::nullableInstant($data, 'resolved_at'),
            Field::instant($data, 'created_at'),
            array_map(Attachment::fromArray(...), Field::objectList($data, 'attachments')),
            array_key_exists('services', $data) ? array_map(Reference::fromArray(...), Field::objectList($data, 'services')) : null,
            array_key_exists('updates', $data) ? array_map(IncidentUpdate::fromArray(...), Field::objectList($data, 'updates')) : null,
            $impact === null ? null : IncidentServiceImpact::fromArray($impact),
            $data,
        );
    }

    public function isResolved(): bool
    {
        return $this->status === IncidentStatus::Resolved;
    }

    public function isOpen(): bool
    {
        return ! $this->isResolved();
    }

    /** A standing advisory that affects no status, availability figure or alert. */
    public function isNotice(): bool
    {
        return $this->kind === IncidentKind::Notice;
    }

    /**
     * @return list<string>
     */
    public function serviceIds(): array
    {
        return array_map(static fn(Reference $service): string => $service->id, $this->services ?? []);
    }
}
