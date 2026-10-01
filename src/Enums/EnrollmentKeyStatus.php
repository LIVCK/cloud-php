<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * Whether an enrollment key can still enroll a server, and if not, why. When several reasons
 * apply, the first of revoked, exhausted and expired is reported: a used-up key that has expired
 * since stays exhausted.
 */
enum EnrollmentKeyStatus: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** Can enroll a server. */
    case Active = 'active';

    /** Every use is taken: a single key after its server enrolled, a fleet key at `maxUses`. */
    case Exhausted = 'exhausted';

    /** Past `expiresAt`. */
    case Expired = 'expired';

    /** Revoked; servers enrolled with it keep running. */
    case Revoked = 'revoked';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
