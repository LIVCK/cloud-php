<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Enums\ConditionOutcome;

/**
 * A field waiting for its operator: `HttpCondition::statusCode()` returns one, the
 * operator method (`gte(500)`) turns it into a condition of the family that created it.
 *
 * The subclasses each offer one operator set of the catalog, so a field only ever accepts
 * the operators the server takes for it.
 *
 * @template T of Condition
 */
abstract readonly class Subject
{
    /**
     * @param class-string<T> $family the condition class the operator methods produce
     */
    final public function __construct(
        protected string $field,
        protected string $family,
    ) {}

    /**
     * @return T
     */
    final protected function make(ConditionOperator $operator, mixed $value): Condition
    {
        return new ($this->family)($this->field, $operator->value, $value, ConditionOutcome::Down->value);
    }
}
