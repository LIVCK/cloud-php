<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * What a matching condition does to the check result (`conditions[].status`). A
 * condition only ever flags a failure; `up` is the implicit outcome when none matches.
 */
enum ConditionOutcome: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Down = 'down';
    case Degraded = 'degraded';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
