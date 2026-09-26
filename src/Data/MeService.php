<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * The service a server agent's managed token is bound to (`GET /v1/me`). Absent for an
 * organization token.
 */
final readonly class MeService
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $publicId,
        public string $name,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'public_id'),
            Field::string($data, 'name'),
            $data,
        );
    }
}
