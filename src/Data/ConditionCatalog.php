<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * The condition catalog of one check type: the assertable fields with their operators, the
 * conditions the server applies when a service is created without any, and, for types
 * whose fields depend on a subtype (DNS: the record type), the map of which fields apply.
 */
final readonly class ConditionCatalog
{
    /**
     * @param list<ConditionField> $fields
     * @param list<ConditionRule> $defaults
     * @param ConditionSubtypeMap|null $bySubtype restricts `fields` per subtype value, when the type has one
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public array $fields,
        public array $defaults,
        public ?ConditionSubtypeMap $bySubtype,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $bySubtype = Field::nullableObject($data, 'by_subtype');

        return new self(
            array_map(ConditionField::fromArray(...), Field::objectList($data, 'fields')),
            array_map(ConditionRule::fromArray(...), Field::objectList($data, 'defaults')),
            $bySubtype === null ? null : ConditionSubtypeMap::fromArray($bySubtype),
            $data,
        );
    }

    /** The field with exactly this name (`status_code`, `json`), or null. */
    public function field(string $name): ?ConditionField
    {
        foreach ($this->fields as $field) {
            if ($field->field === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The field a stored condition name belongs to: an exact match (`status_code`) or the
     * parametric family whose prefix it carries (`json.data.status` → `json`). Null when
     * the catalog knows no such field.
     */
    public function resolve(string $name): ?ConditionField
    {
        foreach ($this->fields as $field) {
            if ($field->matches($name)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The fields that apply for a subtype value (a DNS record type, for instance). Without a
     * subtype map, or for a value the map does not know, every field applies.
     *
     * @return list<ConditionField>
     */
    public function fieldsFor(?string $subtype): array
    {
        $allowed = $subtype === null || ! $this->bySubtype instanceof ConditionSubtypeMap
            ? null
            : $this->bySubtype->fieldsFor($subtype);

        if ($allowed === null) {
            return $this->fields;
        }

        return array_values(array_filter(
            $this->fields,
            static fn(ConditionField $field): bool => in_array($field->field, $allowed, true),
        ));
    }
}
