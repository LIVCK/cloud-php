<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Field;

/**
 * The check-type catalog (`GET /v1/meta/check-types`): per creatable check type, the
 * `settings.config` fields, the assertable condition fields with their operators, the
 * default conditions and, where the type bounds it, the interval range.
 *
 * It is derived live from the server's registry, so it is the reference for what
 * `POST /v1/services` accepts today. {@see \LIVCK\Cloud\Builders\ServiceBuilder::validate()}
 * checks a payload against it before anything is sent.
 */
final readonly class CheckTypeCatalog
{
    /**
     * @param array<string, CheckTypeDefinition> $types keyed by check type (`http`, `tcp`, …)
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public array $types,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data the `data` object, keyed by check type
     */
    public static function fromArray(array $data): self
    {
        $types = [];

        foreach (array_keys($data) as $key) {
            $types[$key] = CheckTypeDefinition::fromArray(Field::object($data, $key));
        }

        return new self($types, $data);
    }

    public function has(CheckType|string $type): bool
    {
        return isset($this->types[$this->keyOf($type)]);
    }

    /**
     * @throws InvalidArgumentException when the catalog has no such check type
     */
    public function type(CheckType|string $type): CheckTypeDefinition
    {
        $key = $this->keyOf($type);

        return $this->types[$key] ?? throw new InvalidArgumentException(sprintf(
            'The check-type catalog has no "%s" type; it offers %s.',
            $key,
            implode(', ', $this->keys()),
        ));
    }

    public function find(CheckType|string $type): ?CheckTypeDefinition
    {
        return $this->types[$this->keyOf($type)] ?? null;
    }

    /**
     * The check types that can be created through the API.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->types);
    }

    /**
     * @return list<CheckTypeDefinition>
     */
    public function all(): array
    {
        return array_values($this->types);
    }

    private function keyOf(CheckType|string $type): string
    {
        return $type instanceof CheckType ? $type->value : $type;
    }
}
