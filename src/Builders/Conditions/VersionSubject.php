<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

use LIVCK\Cloud\Enums\ConditionOperator;

/**
 * A version string compared for equality, membership or by substring
 * (`metadata.tls_version`).
 *
 * @template T of Condition
 * @extends Subject<T>
 */
final readonly class VersionSubject extends Subject
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

    /**
     * @param list<string> $values
     * @return T
     */
    public function in(array $values): Condition
    {
        return $this->make(ConditionOperator::In, $values);
    }

    /**
     * @param list<string> $values
     * @return T
     */
    public function notIn(array $values): Condition
    {
        return $this->make(ConditionOperator::NotIn, $values);
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
