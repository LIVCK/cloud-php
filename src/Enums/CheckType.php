<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * What a service is checked with.
 *
 * Only `http`, `tcp`, `dns`, `icmp`, `ssl` and `manual` can be created through the API
 * (`ServiceBuilder` has one factory per type). The others exist on services that were set
 * up elsewhere and appear in lists: `heartbeat` (push monitoring), `statuspage` (a mirrored
 * third-party status page), `agent` (the server agent) and `push` (custom metrics).
 */
enum CheckType: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case Http = 'http';
    case Tcp = 'tcp';
    case Dns = 'dns';
    case Icmp = 'icmp';
    case Ssl = 'ssl';
    case Manual = 'manual';
    case Heartbeat = 'heartbeat';
    case Statuspage = 'statuspage';
    case Agent = 'agent';
    case Push = 'push';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';

    /**
     * Whether probes run checks for this type. Only these services have a check history,
     * response times and probe assignments; the passive types answer with empty lists.
     */
    public function isMonitoredByProbes(): bool
    {
        return in_array($this, [self::Http, self::Tcp, self::Dns, self::Icmp, self::Ssl, self::Statuspage], true);
    }
}
