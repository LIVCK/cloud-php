<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * What an enrollment key is made for.
 */
enum EnrollmentKeyType: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** Enrolls exactly one server: the key in the install command for one machine. */
    case Single = 'single';

    /** Enrolls up to `maxUses` servers: for images, cloud-init or configuration management. */
    case Fleet = 'fleet';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
