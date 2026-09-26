<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The status of a service, for both `status` (what the checks measured) and
 * `effective_status` (what everyone sees).
 *
 * The measured status is one of `unknown`, `up`, `down`, `degraded` or `paused`. The
 * effective status can additionally be `maintenance` (pinned by a manual override) or
 * `operational`. A `manual` service that nothing measures and nobody has overridden reads
 * `up` from its creation on; `operational` is what the API answers for one whose stored
 * status is still `unknown`. Read {@see isHealthy()} rather than either case.
 */
enum ServiceStatus: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Unknown = 'unknown';
    case Up = 'up';
    case Down = 'down';
    case Degraded = 'degraded';
    case Paused = 'paused';
    case Maintenance = 'maintenance';
    case Operational = 'operational';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';

    /** Reachable and healthy, whichever word the API used for it. */
    public function isHealthy(): bool
    {
        return $this === self::Up || $this === self::Operational;
    }
}
