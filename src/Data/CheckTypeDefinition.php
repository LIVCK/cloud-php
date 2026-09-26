<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Support\Field;

/**
 * One check type of the catalog: identity, the `settings.config` fields it accepts, its
 * condition catalog and, when the type bounds it, its interval range.
 */
final readonly class CheckTypeDefinition
{
    /**
     * @param bool $targetRequired whether `target` must be sent (false for passive types such as `manual`)
     * @param list<ConfigField> $fields the `settings.config` fields, in the server's order
     * @param IntervalBounds|null $interval the type's own interval range (seconds); null when only the plan bounds it
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $description,
        public bool $targetRequired,
        public array $fields,
        public ConditionCatalog $conditions,
        public ?IntervalBounds $interval,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $interval = Field::nullableObject($data, 'interval');

        return new self(
            Field::string($data, 'key'),
            Field::string($data, 'label'),
            Field::nullableString($data, 'description'),
            Field::bool($data, 'target_required'),
            array_map(ConfigField::fromArray(...), Field::objectList($data, 'fields')),
            ConditionCatalog::fromArray(Field::object($data, 'conditions')),
            $interval === null ? null : IntervalBounds::fromArray($interval),
            $data,
        );
    }

    public function checkType(): CheckType
    {
        return CheckType::fromApi($this->key);
    }

    public function field(string $name): ?ConfigField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    public function hasField(string $name): bool
    {
        return $this->field($name) instanceof ConfigField;
    }

    /**
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return array_map(static fn(ConfigField $field): string => $field->name, $this->fields);
    }
}
