<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The IP family of an address the server agent was seen calling from.
 */
enum IpFamily: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case V4 = 'v4';
    case V6 = 'v6';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
