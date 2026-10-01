<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * Where the server agent behind an `agent` service stands. This is the machine's lifecycle; the
 * service's status (up, down, …) is derived from it.
 */
enum AgentState: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** Enrolled, no report yet. */
    case Waiting = 'waiting';

    /** Reporting on time. */
    case Online = 'online';

    /** Reporting, with a condition tripped. */
    case Degraded = 'degraded';

    /** Announced a reboot; expected back within minutes. Not an outage. */
    case Rebooting = 'rebooting';

    /** Updating itself. */
    case Updating = 'updating';

    /** Stopped reporting without notice: an outage. */
    case Offline = 'offline';

    /** Announced a power-off. Not an outage. */
    case Stopped = 'stopped';

    /** Offline for a long time and set aside. */
    case Archived = 'archived';

    /** The agent was uninstalled; the service is paused until it enrolls again. */
    case Uninstalled = 'uninstalled';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';

    /** Whether the server counts as down: it stopped reporting without notice. */
    public function isOutage(): bool
    {
        return $this === self::Offline;
    }
}
