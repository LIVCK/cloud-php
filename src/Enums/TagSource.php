<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * Who maintains a tag.
 */
enum TagSource: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** Created by a person or through the API; fully editable. */
    case User = 'user';

    /**
     * Maintained by LIVCK: derived by the server agent from host facts (`os`, `distro`, `arch`,
     * `virt`, `agent`, `kernel`, `host`), or set on a heartbeat declared from code
     * (`livck.app:<application>`, `livck.env:<environment>`). Key and value are LIVCK's; only
     * the color can be changed, and the tag cannot be removed from a service or deleted.
     */
    case System = 'system';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
