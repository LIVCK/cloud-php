<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * Why the new heartbeats of a declaration were not created. Part of a successful answer: the
 * heartbeats that existed were aligned all the same.
 */
enum HeartbeatNotCreatedReason: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** The plan has no room for all new heartbeats; `missing` says how many places are short. */
    case ServiceLimit = 'service_limit';

    /**
     * Too many services were created in the last hour or day; `retryAfter` says when there is
     * room again, null when the list is larger than one hour or day allows at all.
     */
    case CreationRate = 'creation_rate';

    /** Creating services is switched off for the organization. */
    case Blocked = 'blocked';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
