<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

use LIVCK\Cloud\Enums\ConditionOperator;

/**
 * A number compared for equality or order (`metadata.ip_count`, `metadata.days_until_expiry`).
 *
 * @template T of Condition
 * @extends Subject<T>
 */
final readonly class NumberSubject extends Subject
{
    /** @return T */
    public function eq(int|float $value): Condition
    {
        return $this->make(ConditionOperator::Eq, $value);
    }

    /** @return T */
    public function neq(int|float $value): Condition
    {
        return $this->make(ConditionOperator::Neq, $value);
    }

    /** @return T */
    public function gt(int|float $value): Condition
    {
        return $this->make(ConditionOperator::Gt, $value);
    }

    /** @return T */
    public function gte(int|float $value): Condition
    {
        return $this->make(ConditionOperator::Gte, $value);
    }

    /** @return T */
    public function lt(int|float $value): Condition
    {
        return $this->make(ConditionOperator::Lt, $value);
    }

    /** @return T */
    public function lte(int|float $value): Condition
    {
        return $this->make(ConditionOperator::Lte, $value);
    }
}
