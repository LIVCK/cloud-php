<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * What class of thing an incident is.
 */
enum IncidentKind: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** A service disruption, declared by hand or detected by the probes. */
    case Standard = 'standard';

    /** A server agent stopped reporting. */
    case AgentLiveness = 'agent_liveness';

    /**
     * A standing advisory that never affects a status, availability figure or alert. Its
     * severity is always `minor` and carries no meaning.
     */
    case Notice = 'notice';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
