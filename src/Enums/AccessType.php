<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * Who may open a status page.
 */
enum AccessType: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** Everyone. Every new page starts public. */
    case Public = 'public';

    /**
     * Visitors enter a shared password. Switching to it needs a password, sent with the same
     * update or stored before; switching away from it deletes the stored password.
     */
    case Password = 'password';

    /** Only the addresses on the page's email whitelist; the whitelist must not be empty. */
    case EmailWhitelist = 'email_whitelist';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
