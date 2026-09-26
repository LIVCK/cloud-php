<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use BackedEnum;
use LIVCK\Cloud\Support\Field;

/**
 * One `settings.config` field of a check type: its name, input type (`select`, `boolean`,
 * `key_value_array`, `auth`, `textarea`, …), whether it is required, whether its value is a
 * secret (encrypted at rest, read back as the keep sentinel), the options of a select and
 * the default the server applies when the field is omitted.
 */
final readonly class ConfigField
{
    /**
     * @param list<mixed>|null $options the accepted values of a `select`; null for other types
     * @param mixed $default the server's default; only meaningful when `hasDefault` is true
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $label,
        public bool $required,
        public bool $secret,
        public ?string $help,
        public ?array $options,
        public mixed $default,
        public bool $hasDefault,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'name'),
            Field::string($data, 'type'),
            Field::nullableString($data, 'label'),
            Field::bool($data, 'required'),
            Field::bool($data, 'secret'),
            Field::nullableString($data, 'help'),
            array_key_exists('options', $data) ? Field::list($data, 'options') : null,
            $data['default'] ?? null,
            array_key_exists('default', $data),
            $data,
        );
    }

    public function isSelect(): bool
    {
        return $this->type === 'select' && $this->options !== null;
    }

    public function isBoolean(): bool
    {
        return $this->type === 'boolean';
    }

    /**
     * Whether a value is one of the select's options. Enums are compared by their backing
     * value, everything else by its string form (`GET`, `ipv4`, `NS`). True for a field that
     * is not a select.
     */
    public function allowsOption(mixed $value): bool
    {
        if ($this->options === null) {
            return true;
        }

        $candidate = $value instanceof BackedEnum ? $value->value : $value;

        if (! is_scalar($candidate)) {
            return false;
        }

        foreach ($this->options as $option) {
            if (is_scalar($option) && (string) $option === (string) $candidate) {
                return true;
            }
        }

        return false;
    }
}
