<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

/**
 * One declared response of an operation: its status and one schema per content type.
 */
final readonly class ResponseSpec
{
    /**
     * @param array<string, string> $schemaPointers content type => pointer to its schema
     */
    public function __construct(
        public string $status,
        public array $schemaPointers,
    ) {}

    public function jsonPointer(): ?string
    {
        return $this->schemaPointers['application/json'] ?? null;
    }
}
