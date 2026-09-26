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
     * Derived by the server agent from host facts (`os`, `distro`, `arch`, `virt`, `agent`,
     * `kernel`, `host`). Key and value are owned by the agent; only the color can be changed.
     */
    case System = 'system';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
