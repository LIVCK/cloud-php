<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * What a declared heartbeat watches. It labels the heartbeat in LIVCK; the schedule, not the
 * kind, decides when a ping is due.
 */
enum HeartbeatKind: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** A scheduled task, such as a nightly export run by cron or a framework scheduler. */
    case Schedule = 'schedule';

    /** A job that runs on its own, such as a backup or an import. */
    case Job = 'job';

    /** A queue worker that pings while it processes. */
    case Queue = 'queue';

    /** Anything else that reports in. */
    case Custom = 'custom';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
