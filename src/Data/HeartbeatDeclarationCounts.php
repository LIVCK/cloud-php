<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * How many heartbeats of a declaration were created, updated and left unchanged.
 */
final readonly class HeartbeatDeclarationCounts
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $unchanged,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::int($data, 'created'),
            Field::int($data, 'updated'),
            Field::int($data, 'unchanged'),
            $data,
        );
    }

    /** Whether the declaration wrote anything. */
    public function changedAnything(): bool
    {
        return $this->created > 0 || $this->updated > 0;
    }
}
