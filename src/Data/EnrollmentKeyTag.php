<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * A tag as an enrollment key carries it: id, key, value, color and label. Every server enrolled
 * with the key gets it. `tags()->get($id)` returns the full {@see Tag}.
 */
final readonly class EnrollmentKeyTag
{
    /**
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $id,
        public string $key,
        public ?string $value,
        public string $color,
        public string $label,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'id'),
            Field::string($data, 'key'),
            Field::nullableString($data, 'value'),
            Field::string($data, 'color'),
            Field::string($data, 'label'),
            $data,
        );
    }
}
