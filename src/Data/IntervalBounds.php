<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * A check type's own interval range in seconds (`interval` in the catalog; SSL checks, for
 * instance, run hourly at most). The plan's minimum interval applies on top of it and is
 * enforced by the server only.
 */
final readonly class IntervalBounds
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?int $default,
        public ?int $min,
        public ?int $max,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::nullableInt($data, 'default'),
            Field::nullableInt($data, 'min'),
            Field::nullableInt($data, 'max'),
            $data,
        );
    }

    public function allows(int $seconds): bool
    {
        return ($this->min === null || $seconds >= $this->min) && ($this->max === null || $seconds <= $this->max);
    }
}
