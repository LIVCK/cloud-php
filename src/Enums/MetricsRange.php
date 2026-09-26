<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The time window of `GET /v1/services/{id}/metrics` and `/response-times`.
 */
enum MetricsRange: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case OneHour = '1h';
    case SixHours = '6h';
    case TwentyFourHours = '24h';
    case SevenDays = '7d';
    case ThirtyDays = '30d';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
