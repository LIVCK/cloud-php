<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * A heartbeat declared earlier for the same application and environment that the declaration
 * did not name. LIVCK left it as it was: it keeps expecting pings, and goes down when none
 * arrive, until it is deleted (`services()->delete($serviceId)`).
 */
final readonly class UnlistedHeartbeat
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $key,
        public string $serviceId,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'key'),
            Field::string($data, 'service_id'),
            $data,
        );
    }
}
