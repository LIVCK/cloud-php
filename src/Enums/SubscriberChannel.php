<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * A channel visitors can subscribe to a status page through.
 */
enum SubscriberChannel: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** The only channel of a new page. */
    case Email = 'email';

    case Webhook = 'webhook';

    case Slack = 'slack';

    case Teams = 'teams';

    case Discord = 'discord';

    case Telegram = 'telegram';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
