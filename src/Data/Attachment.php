<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * A PDF attached to an incident or maintenance.
 */
final readonly class Attachment
{
    /**
     * @param int $size bytes
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $size,
        public string $url,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'id'),
            Field::string($data, 'name'),
            Field::int($data, 'size'),
            Field::string($data, 'url'),
            $data,
        );
    }
}
