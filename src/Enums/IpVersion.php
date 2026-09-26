<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * Which address family a check connects over (`config.ip_version`). `auto` tries both
 * and reports up as soon as either answers; `ipv4`/`ipv6` force one family with no
 * fallback.
 */
enum IpVersion: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Auto = 'auto';
    case Ipv4 = 'ipv4';
    case Ipv6 = 'ipv6';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
