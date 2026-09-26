<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * A compact pointer to another resource, id and name only: the services and statuspages
 * embedded in an incident or maintenance. Fetch the resource itself for anything more.
 */
final readonly class Reference
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $name,
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
            $data,
        );
    }
}
