<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Data\Concerns\ReadsStringMaps;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Enums\MaintenanceType;
use LIVCK\Cloud\Support\Field;

/**
 * A maintenance window (`/v1/maintenances`, `/v1/services/{id}/maintenances`).
 *
 * `title` is resolved to the client's locale by the server; `titleTranslations` carries
 * every translation. `statuspages` and `updates` are included by `get()` only.
 */
final readonly class Maintenance
{
    use ReadsStringMaps;

    /**
     * @param array<string, string>|null $titleTranslations locale => title; null when the title has no translations
     * @param DateTimeImmutable|null $scheduledEnd null for an open-ended window that is ended by hand
     * @param list<Attachment> $attachments
     * @param list<Reference>|null $statuspages the statuspages that show the window; included by `get()` only
     * @param list<Reference>|null $services the covered services; null when the endpoint did not include them
     * @param list<MaintenanceUpdate>|null $updates the timeline; included by `get()` only
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?array $titleTranslations,
        public MaintenanceType $type,
        public MaintenanceStatus $status,
        public DateTimeImmutable $scheduledStart,
        public ?DateTimeImmutable $scheduledEnd,
        public bool $autoStart,
        public bool $autoComplete,
        public DateTimeImmutable $createdAt,
        public array $attachments,
        public ?array $statuspages,
        public ?array $services,
        public ?array $updates,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'id'),
            Field::string($data, 'title'),
            self::nullableStringMap($data, 'title_translations'),
            MaintenanceType::fromApi(Field::string($data, 'type')),
            MaintenanceStatus::fromApi(Field::string($data, 'status')),
            Field::instant($data, 'scheduled_start'),
            Field::nullableInstant($data, 'scheduled_end'),
            Field::bool($data, 'auto_start'),
            Field::bool($data, 'auto_complete'),
            Field::instant($data, 'created_at'),
            array_map(Attachment::fromArray(...), Field::objectList($data, 'attachments')),
            array_key_exists('statuspages', $data) ? array_map(Reference::fromArray(...), Field::objectList($data, 'statuspages')) : null,
            array_key_exists('services', $data) ? array_map(Reference::fromArray(...), Field::objectList($data, 'services')) : null,
            array_key_exists('updates', $data) ? array_map(MaintenanceUpdate::fromArray(...), Field::objectList($data, 'updates')) : null,
            $data,
        );
    }

    /** Still ahead or running. */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** A window without a planned end ("until further notice"). */
    public function isOpenEnded(): bool
    {
        return ! $this->scheduledEnd instanceof DateTimeImmutable;
    }

    /**
     * @return list<string>
     */
    public function serviceIds(): array
    {
        return array_map(static fn(Reference $service): string => $service->id, $this->services ?? []);
    }
}
