<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Enums\IncidentStatus;
use LIVCK\Cloud\Support\Field;

/**
 * One public entry of an incident's timeline. Internal notes never leave the server.
 */
final readonly class IncidentUpdate
{
    /**
     * @param string $message localized to the client's locale
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public IncidentStatus $status,
        public string $message,
        public bool $notifySubscribers,
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
            IncidentStatus::fromApi(Field::string($data, 'status')),
            Field::string($data, 'message'),
            Field::bool($data, 'notify_subscribers'),
            Field::instant($data, 'created_at'),
            $data,
        );
    }
}
