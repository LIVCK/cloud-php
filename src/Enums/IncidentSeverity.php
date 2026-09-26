<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * How severe an incident is as a whole. What it meant for ONE service is the
 * {@see ServiceImpact} on the service-scoped incident list.
 */
enum IncidentSeverity: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
