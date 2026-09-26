<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The lifecycle status of an incident and of each of its updates. Everything but
 * `resolved` counts as open.
 */
enum IncidentStatus: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';

    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }
}
