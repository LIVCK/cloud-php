<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * Why a custom domain is not verified (yet), as reported in its `last_error_code`.
 *
 * The first four come from the latest DNS check of a pending domain and tell the end
 * customer what to fix; the last two explain a failed one.
 */
enum CustomDomainErrorCode: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** The hostname does not resolve at all yet. */
    case NoRecords = 'no_records';

    /** The hostname resolves, but not to the LIVCK edge: the CNAME points elsewhere. */
    case WrongTarget = 'wrong_target';

    /** Routing is right, but the TXT record with the verification token is missing or differs. */
    case OwnershipMissing = 'ownership_missing';

    /** Resolving the hostname fails with SERVFAIL, typically a broken DNSSEC setup. */
    case Dnssec = 'dnssec';

    /** The background checks ran out of attempts without finding the records. */
    case VerificationTimeout = 'verification_timeout';

    /** The domain stayed pending for more than seven days. */
    case StaleTimeout = 'stale_timeout';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
