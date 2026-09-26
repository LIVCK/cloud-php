<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

use LIVCK\Cloud\Enums\ConditionOperator;

/**
 * A text compared for equality or by substring (`header.<name>`, `metadata.cname`,
 * `metadata.issuer`).
 *
 * @template T of Condition
 * @extends Subject<T>
 */
final readonly class StringSubject extends Subject
{
    /** @return T */
    public function eq(string $value): Condition
    {
        return $this->make(ConditionOperator::Eq, $value);
    }

    /** @return T */
    public function neq(string $value): Condition
    {
        return $this->make(ConditionOperator::Neq, $value);
    }

    /** @return T */
    public function contains(string $value): Condition
    {
        return $this->make(ConditionOperator::Contains, $value);
    }

    /** @return T */
    public function notContains(string $value): Condition
    {
        return $this->make(ConditionOperator::NotContains, $value);
    }
}
