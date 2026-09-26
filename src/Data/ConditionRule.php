<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Enums\ConditionOutcome;
use LIVCK\Cloud\Support\Field;

/**
 * A condition as the server stores it: on a service's `settings.config.conditions` and as
 * a check type's default in the catalog. Its wire name for the outcome is `status`.
 */
final readonly class ConditionRule
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $field,
        public ConditionOperator $operator,
        public mixed $value,
        public ConditionOutcome $outcome,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'field'),
            ConditionOperator::fromApi(Field::string($data, 'operator')),
            $data['value'] ?? null,
            ConditionOutcome::fromApi(Field::string($data, 'status')),
            $data,
        );
    }
}
