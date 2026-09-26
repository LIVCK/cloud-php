<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * Whether a maintenance window was planned ahead or declared in an emergency.
 */
enum MaintenanceType: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Planned = 'planned';
    case Emergency = 'emergency';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
