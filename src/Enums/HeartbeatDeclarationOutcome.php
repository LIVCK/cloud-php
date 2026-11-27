<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * What a declaration did to one heartbeat.
 */
enum HeartbeatDeclarationOutcome: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** The key was new; the heartbeat was created and waits for its first ping. */
    case Created = 'created';

    /** The declaration changed; the heartbeat was brought in line with it. */
    case Updated = 'updated';

    /** The heartbeat was declared as before; nothing was written. */
    case Unchanged = 'unchanged';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
