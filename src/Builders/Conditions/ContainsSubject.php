<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

use LIVCK\Cloud\Enums\ConditionOperator;

/**
 * A text or list searched for a member (`body`, `metadata.ips`, `metadata.dns_names`).
 *
 * @template T of Condition
 * @extends Subject<T>
 */
final readonly class ContainsSubject extends Subject
{
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
