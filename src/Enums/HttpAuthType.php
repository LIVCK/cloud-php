<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * How an HTTP check authenticates (`config.auth.type`). The secret part (`token`,
 * `password`, `value`) is write-only and reads back as the keep sentinel.
 */
enum HttpAuthType: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case None = 'none';
    case Bearer = 'bearer';
    case Basic = 'basic';
    case ApiKey = 'api_key';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
