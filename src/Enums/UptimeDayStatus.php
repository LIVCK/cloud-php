<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The verdict on one day of `GET /v1/services/{id}/uptime`: at least 99.9 % is `up`, at
 * least 95 % `degraded`, less is `down`. `no_data` means nothing was measured that day
 * (the service did not exist, was never configured, or the day lies beyond the plan's
 * retention); its `uptime_percent` is a placeholder, not a measurement.
 */
enum UptimeDayStatus: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Up = 'up';
    case Degraded = 'degraded';
    case Down = 'down';
    case NoData = 'no_data';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
