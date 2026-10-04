<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The light or dark mode of a status page. Independent of the page's theme, which is its
 * layout.
 */
enum StatuspageAppearance: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** Follows the light or dark setting of the visitor's device, the default. */
    case System = 'system';

    /** Light. */
    case Light = 'light';

    /** Dark. */
    case Dark = 'dark';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
