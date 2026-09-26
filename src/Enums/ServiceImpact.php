<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * How badly one service was affected by an incident (`service_impact.impact` on
 * `GET /v1/services/{id}/incidents`). Only `partial_outage` and `major_outage` count as
 * downtime; a degraded service is reachable.
 */
enum ServiceImpact: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Degraded = 'degraded';
    case PartialOutage = 'partial_outage';
    case MajorOutage = 'major_outage';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';

    public function countsAsDowntime(): bool
    {
        return $this === self::PartialOutage || $this === self::MajorOutage;
    }
}
