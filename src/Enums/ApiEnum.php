<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

/**
 * A response enum that survives values this SDK version does not know.
 *
 * The API may add cases before the SDK does. {@see fromApi()} maps an unknown value to
 * the enum's `Unrecognized` case instead of throwing; the original string stays in the
 * DTO's `$raw` payload. Sending `Unrecognized` back to the API is refused by the query
 * encoder and the JSON codec.
 */
interface ApiEnum
{
    public static function fromApi(string $value): static;

    public function isUnrecognized(): bool;
}
