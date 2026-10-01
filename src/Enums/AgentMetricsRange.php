<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The window of `GET /v1/services/{id}/agent-metrics/history`, ending now. A window longer than
 * the plan keeps data for is shortened to the retention.
 */
enum AgentMetricsRange: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case OneHour = '1h';
    case SixHours = '6h';
    case TwentyFourHours = '24h';
    case SevenDays = '7d';
    case ThirtyDays = '30d';
    case NinetyDays = '90d';
    case ThreeHundredSixtyFiveDays = '365d';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
