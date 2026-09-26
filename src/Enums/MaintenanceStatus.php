<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The lifecycle status of a maintenance window and of each of its updates.
 */
enum MaintenanceStatus: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';

    /** Still ahead or running. */
    public function isActive(): bool
    {
        return $this === self::Scheduled || $this === self::InProgress;
    }
}
