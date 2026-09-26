<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The role a monitoring location plays for a service (`settings.probe_roles`).
 *
 * A location without an entry is `full`. A `reachability` location still checks, still
 * triggers incidents and alerts, but is left out of the uptime and response-time figures.
 * At least one location must stay `full`; the server refuses a map that leaves none.
 */
enum ProbeRole: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Full = 'full';
    case Reachability = 'reachability';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
