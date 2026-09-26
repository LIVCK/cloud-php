<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

use LIVCK\Cloud\Enums\ConditionOperator;

/**
 * An integer code compared for equality, order or membership (`status_code`).
 *
 * @template T of Condition
 * @extends Subject<T>
 */
final readonly class CodeSubject extends Subject
{
    /** @return T */
    public function eq(int $code): Condition
    {
        return $this->make(ConditionOperator::Eq, $code);
    }

    /** @return T */
    public function neq(int $code): Condition
    {
        return $this->make(ConditionOperator::Neq, $code);
    }

    /** @return T */
    public function gt(int $code): Condition
    {
        return $this->make(ConditionOperator::Gt, $code);
    }

    /** @return T */
    public function gte(int $code): Condition
    {
        return $this->make(ConditionOperator::Gte, $code);
    }

    /** @return T */
    public function lt(int $code): Condition
    {
        return $this->make(ConditionOperator::Lt, $code);
    }

    /** @return T */
    public function lte(int $code): Condition
    {
        return $this->make(ConditionOperator::Lte, $code);
    }

    /**
     * @param list<int> $codes
     * @return T
     */
    public function in(array $codes): Condition
    {
        return $this->make(ConditionOperator::In, $codes);
    }

    /**
     * @param list<int> $codes
     * @return T
     */
    public function notIn(array $codes): Condition
    {
        return $this->make(ConditionOperator::NotIn, $codes);
    }
}
