<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * How a heartbeat expects its pings.
 */
enum HeartbeatScheduleType: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** A ping every so many seconds. */
    case Simple = 'simple';

    /** A ping whenever a cron expression is due. */
    case Cron = 'cron';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
