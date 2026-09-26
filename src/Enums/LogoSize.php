<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The height of the logo in the header of a status page.
 */
enum LogoSize: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** 20 px. */
    case Small = 'small';

    /** 28 px, the default. */
    case Medium = 'medium';

    /** 40 px. */
    case Large = 'large';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
