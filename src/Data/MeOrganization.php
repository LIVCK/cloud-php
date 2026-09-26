<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * The organization a token belongs to (`GET /v1/me`).
 */
final readonly class MeOrganization
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
