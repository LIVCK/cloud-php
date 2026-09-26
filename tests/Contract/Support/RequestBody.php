<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

/**
 * The request body an operation declares: one schema per content type.
 */
final readonly class RequestBody
{
    /**
     * @param array<string, string> $schemaPointers content type => pointer to its schema
     */
    public function __construct(
        public bool $required,
        public array $schemaPointers,
    ) {}

    public function pointerFor(string $contentType): ?string
    {
        return $this->schemaPointers[$contentType] ?? null;
    }

    /**
     * @return list<string>
     */
    public function contentTypes(): array
    {
        return array_keys($this->schemaPointers);
    }
}
