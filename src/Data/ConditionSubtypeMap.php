<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * Which condition fields apply per value of a config field (`conditions.by_subtype`):
 * for DNS, `dns_type` decides that an `NS` check may assert `metadata.ns_count` but not
 * `metadata.ips`.
 */
final readonly class ConditionSubtypeMap
{
    /**
     * @param string $field the config field whose value selects the subtype (`dns_type`)
     * @param array<string, list<string>> $map subtype value => the condition field names that apply
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $field,
        public array $map,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $raw = Field::object($data, 'map');
        $map = [];

        foreach (array_keys($raw) as $subtype) {
            $map[$subtype] = Field::stringList($raw, $subtype);
        }

        return new self(Field::string($data, 'field'), $map, $data);
    }

    /**
     * The condition field names for a subtype value, or null for a value the map does not
     * know (every field applies then).
     *
     * @return list<string>|null
     */
    public function fieldsFor(string $subtype): ?array
    {
        return $this->map[$subtype] ?? null;
    }

    /**
     * @return list<string>
     */
    public function subtypes(): array
    {
        return array_keys($this->map);
    }
}
