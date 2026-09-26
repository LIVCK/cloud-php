<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * What kind of token is calling (`GET /v1/me`).
 */
enum TokenType: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** An organization API token created under Settings > API Tokens. */
    case User = 'user';

    /** A server agent's own token: bound to one service, never expires, ingest only. */
    case Managed = 'managed';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
