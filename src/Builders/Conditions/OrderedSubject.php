<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

use LIVCK\Cloud\Enums\ConditionOperator;

/**
 * A measurement compared by order only (`response_time_ms`, `metadata.max_rtt_ms`).
 *
 * @template T of Condition
 * @extends Subject<T>
 */
final readonly class OrderedSubject extends Subject
{
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
