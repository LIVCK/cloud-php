<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums\Concerns;

/**
 * The {@see \LIVCK\Cloud\Enums\ApiEnum} implementation for a string-backed enum that
 * declares an `Unrecognized` case.
 */
trait ToleratesUnknownValues
{
    public static function fromApi(string $value): static
    {
        return self::tryFrom($value) ?? self::Unrecognized;
    }

    public function isUnrecognized(): bool
    {
        return $this === self::Unrecognized;
    }
}
