<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

use InvalidArgumentException;

/**
 * One declared parameter of an operation (query, path or header).
 */
final readonly class Parameter
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        public string $name,
        public string $in,
        public bool $required,
        public string $schemaPointer,
        public array $schema,
    ) {}

    /**
     * @param array<string, mixed> $parameter
     */
    public static function fromArray(array $parameter, string $schemaPointer): self
    {
        $name = $parameter['name'] ?? null;
        $in = $parameter['in'] ?? null;

        if (! is_string($name) || ! is_string($in)) {
            throw new InvalidArgumentException('A parameter needs a name and a location.');
        }

        $schema = $parameter['schema'] ?? null;

        /** @var array<string, mixed> $schema */
        $schema = is_array($schema) ? $schema : [];

        return new self($name, $in, (bool) ($parameter['required'] ?? false), $schemaPointer, $schema);
    }

    /** The name without the `[]` suffix that marks a repeatable parameter. */
    public function baseName(): string
    {
        return str_ends_with($this->name, '[]') ? substr($this->name, 0, -2) : $this->name;
    }

    public function isList(): bool
    {
        return str_ends_with($this->name, '[]') || $this->hasType('array');
    }

    public function hasType(string $type): bool
    {
        $declared = $this->schema['type'] ?? null;

        return is_array($declared) ? in_array($type, $declared, true) : $declared === $type;
    }

    /**
     * @return array<string, mixed>
     */
    public function itemSchema(): array
    {
        $items = $this->schema['items'] ?? null;

        /** @var array<string, mixed> $items */
        $items = is_array($items) ? $items : [];

        return $items;
    }
}
