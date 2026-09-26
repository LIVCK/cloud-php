<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * Where a custom domain of a status page stands.
 */
enum CustomDomainStatus: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /**
     * Waiting for the DNS records. The server checks on its own, every few seconds at first
     * and then less often; {@see \LIVCK\Cloud\Resources\CustomDomainsInterface::verify()}
     * checks right away. `lastErrorCode` says what is still missing.
     */
    case PendingVerification = 'pending_verification';

    /** Verified: the page answers under this hostname. */
    case Active = 'active';

    /**
     * The server stopped checking because the records did not appear in time. A failed
     * domain cannot be verified again and still counts against the plan's domain limit:
     * detach it and attach the hostname again.
     */
    case Failed = 'failed';

    /** Detached. The API never returns detached domains; they read as not found. */
    case Removing = 'removing';

    /** Detached. The API never returns detached domains; they read as not found. */
    case Removed = 'removed';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
