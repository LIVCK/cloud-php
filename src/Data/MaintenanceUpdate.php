<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Support\Field;

/**
 * One entry of a maintenance window's timeline.
 */
final readonly class MaintenanceUpdate
{
    /**
     * @param string $message localized to the client's locale
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public MaintenanceStatus $status,
        public string $message,
        public DateTimeImmutable $createdAt,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'id'),
            MaintenanceStatus::fromApi(Field::string($data, 'status')),
            Field::string($data, 'message'),
            Field::instant($data, 'created_at'),
            $data,
        );
    }
}
