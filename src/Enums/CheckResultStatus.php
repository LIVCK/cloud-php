<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * What ONE check reported. Not the service status: a single check never says
 * `maintenance`, `paused` or `unknown`.
 */
enum CheckResultStatus: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Up = 'up';
    case Down = 'down';
    case Degraded = 'degraded';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
