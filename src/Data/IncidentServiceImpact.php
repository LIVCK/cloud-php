<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Enums\ServiceImpact;
use LIVCK\Cloud\Support\Field;

/**
 * What an incident meant for ONE service (`service_impact` on
 * `GET /v1/services/{id}/incidents`): how badly it was affected, whether and when it
 * recovered. A service can recover before the incident as a whole is resolved.
 */
final readonly class IncidentServiceImpact
{
    /**
     * @param DateTimeImmutable|null $addedAt when the service became part of the incident
     * @param DateTimeImmutable|null $recoveredAt null while the service is still affected
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?ServiceImpact $impact,
        public bool $isRecovered,
        public ?DateTimeImmutable $addedAt,
        public ?DateTimeImmutable $recoveredAt,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $impact = Field::nullableString($data, 'impact');

        return new self(
            $impact === null ? null : ServiceImpact::fromApi($impact),
            Field::bool($data, 'is_recovered'),
            Field::nullableInstant($data, 'added_at'),
            Field::nullableInstant($data, 'recovered_at'),
            $data,
        );
    }
}
