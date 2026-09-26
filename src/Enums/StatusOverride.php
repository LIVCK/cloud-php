<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The statuses a manual override can pin a service to (`POST /v1/services/{id}/status-override`),
 * and the value of a service's `status_override` field while one is set.
 */
enum StatusOverride: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Up = 'up';
    case Down = 'down';
    case Degraded = 'degraded';
    case Maintenance = 'maintenance';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
