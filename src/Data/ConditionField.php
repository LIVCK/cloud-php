<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Support\Field;

/**
 * One assertable field of a check type (`conditions.fields[]` in the catalog): its
 * operators, unit and, for a parametric family (`json.<path>`, `header.<name>`), the prefix
 * a stored condition name starts with.
 */
final readonly class ConditionField
{
    /**
     * @param list<string> $operators the operators the server accepts for this field
     * @param bool $parametric whether the stored name is `prefix` + a caller-supplied key
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $field,
        public ?string $label,
        public string $type,
        public array $operators,
        public ?string $unit,
        public bool $parametric,
        public ?string $prefix,
        public ?string $keyLabel,
        public ?string $keyPlaceholder,
        public ?string $keyHelp,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'field'),
            Field::nullableString($data, 'label'),
            Field::string($data, 'type'),
            Field::stringList($data, 'operators'),
            Field::nullableString($data, 'unit'),
            Field::nullableBool($data, 'parametric') ?? false,
            Field::nullableString($data, 'prefix'),
            Field::nullableString($data, 'key_label'),
            Field::nullableString($data, 'key_placeholder'),
            Field::nullableString($data, 'key_help'),
            $data,
        );
    }

    public function allows(ConditionOperator|string $operator): bool
    {
        $value = $operator instanceof ConditionOperator ? $operator->value : $operator;

        return in_array($value, $this->operators, true);
    }

    /**
     * Whether a stored condition name belongs to this field: exactly its name, or, for a
     * parametric family, its prefix followed by a non-empty key.
     */
    public function matches(string $name): bool
    {
        if (! $this->parametric || $this->prefix === null) {
            return $name === $this->field;
        }

        return str_starts_with($name, $this->prefix) && trim(substr($name, strlen($this->prefix))) !== '';
    }
}
