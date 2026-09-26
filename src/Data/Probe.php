<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * A monitoring location (`GET /v1/probes`). The `code` (`ffm`, `hel`, `nyc`) is what a
 * service's `settings.assigned_probes` and `probe_roles` refer to, and what a check result
 * names as its `probe`.
 */
final readonly class Probe
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $location,
        public ?string $countryCode,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'code'),
            Field::string($data, 'name'),
            Field::nullableString($data, 'location'),
            Field::nullableString($data, 'country_code'),
            $data,
        );
    }
}
