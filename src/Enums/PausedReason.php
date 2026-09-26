<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * Why a service is paused. Only `manual` is something a client did; the others are the
 * platform's doing and are lifted by the platform as well.
 */
enum PausedReason: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** Paused by a person or through the API; `resume()` lifts it. */
    case Manual = 'manual';

    /** The plan's service quota was exceeded (after a downgrade, for instance). */
    case PlanLimit = 'plan_limit';

    /** The organization is being deleted. */
    case OrgDeleted = 'org_deleted';

    /** A server-agent service beyond the plan's agent capacity. */
    case AgentLimit = 'agent_limit';

    /** A server-agent service whose agent was uninstalled. */
    case AgentUninstalled = 'agent_uninstalled';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
