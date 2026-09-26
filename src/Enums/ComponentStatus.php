<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The status of a statuspage component.
 *
 * Read it from {@see \LIVCK\Cloud\Data\StatuspageComponent::$effectiveStatus}: that is what
 * visitors of the public page see. The component's plain `status` is a stored legacy value
 * the page does not read.
 */
enum ComponentStatus: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Operational = 'operational';

    case Degraded = 'degraded';

    case PartialOutage = 'partial_outage';

    case MajorOutage = 'major_outage';

    /** An active maintenance covers the component. */
    case UnderMaintenance = 'under_maintenance';

    /**
     * No current data (the monitored service went silent). Shown neutral, never as an
     * outage; a group turns `unknown` only when all of its visible children are.
     */
    case Unknown = 'unknown';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
