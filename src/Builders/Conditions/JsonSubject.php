<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

use LIVCK\Cloud\Enums\ConditionOperator;

/**
 * A value at a JSON path of the response body (`json.<path>`): compared for equality,
 * by order or by substring, whatever the path holds.
 *
 * @template T of Condition
 * @extends Subject<T>
 */
final readonly class JsonSubject extends Subject
{
    /** @return T */
    public function eq(int|float|string|bool $value): Condition
    {
        return $this->make(ConditionOperator::Eq, $value);
    }

    /** @return T */
    public function neq(int|float|string|bool $value): Condition
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
