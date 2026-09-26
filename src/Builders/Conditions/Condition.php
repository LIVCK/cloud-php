<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Enums\ConditionOutcome;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * One assertion on a check result (`settings.config.conditions[]`): a field, an operator,
 * a value and the outcome when it matches. A condition only ever flags a failure; a check
 * that matches none of them is `up`.
 *
 * The typed families ({@see HttpCondition}, {@see TcpCondition}, {@see DnsCondition},
 * {@see IcmpCondition}, {@see SslCondition}) offer the fields and operators of the catalog
 * as methods; {@see custom()} expresses a field or operator this SDK version does not know.
 * The outcome defaults to `down`; {@see degraded()} switches it.
 *
 * Which fields and operators a check type accepts is the server's decision (422). The
 * catalog check of {@see \LIVCK\Cloud\Builders\ServiceBuilder::validate()} finds the same
 * mistakes before anything is sent.
 */
abstract readonly class Condition
{
    /**
     * @param string $field the stored field name (`status_code`, `json.data.status`, `metadata.ip_count`)
     * @param string $operator the operator's wire value (`gte`, `contains`, …)
     * @param mixed $value a scalar, or a flat list of scalars for `in` / `not_in`
     * @param string $outcome the outcome's wire value (`down`, `degraded`)
     */
    final public function __construct(
        public string $field,
        public string $operator,
        public mixed $value,
        public string $outcome,
    ) {
        if (trim($field) === '') {
            throw new InvalidArgumentException('A condition field must not be blank.');
        }

        if (trim($operator) === '') {
            throw new InvalidArgumentException('A condition operator must not be blank.');
        }

        if (trim($outcome) === '') {
            throw new InvalidArgumentException('A condition outcome must not be blank.');
        }

        if (! $this->isAllowedValue($value)) {
            throw new InvalidArgumentException(sprintf('A condition value is a scalar or a flat list of scalars, %s given.', get_debug_type($value)));
        }
    }

    /**
     * A condition on any field, for what the typed families do not cover yet. Unknown
     * operators and outcomes are accepted as strings; the server decides.
     */
    public static function custom(
        string $field,
        ConditionOperator|string $operator,
        mixed $value,
        ConditionOutcome|string $outcome = ConditionOutcome::Down,
    ): CustomCondition {
        return new CustomCondition($field, self::wire($operator), $value, self::wire($outcome));
    }

    /** The check is reported `down` when this matches (the default). */
    public function down(): static
    {
        return $this->withOutcome(ConditionOutcome::Down);
    }

    /** The check is reported `degraded` when this matches. */
    public function degraded(): static
    {
        return $this->withOutcome(ConditionOutcome::Degraded);
    }

    public function withOutcome(ConditionOutcome|string $outcome): static
    {
        return new static($this->field, $this->operator, $this->value, self::wire($outcome));
    }

    /**
     * The wire form; the outcome travels as `status`.
     *
     * @return array{field: string, operator: string, value: mixed, status: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'operator' => $this->operator,
            'value' => $this->value,
            'status' => $this->outcome,
        ];
    }

    private static function wire(ConditionOperator|ConditionOutcome|string $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value->isUnrecognized()) {
            throw new InvalidArgumentException(sprintf('%s::Unrecognized stands for a value this SDK version does not know and cannot be sent.', $value::class));
        }

        return $value->value;
    }

    private function isAllowedValue(mixed $value): bool
    {
        if (is_scalar($value)) {
            return true;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }
}
