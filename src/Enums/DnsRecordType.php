<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The record type a DNS check resolves (`config.dns_type`). It also decides which
 * condition fields apply: an `NS` check produces `metadata.ns_*`, never `metadata.ips`.
 */
enum DnsRecordType: string implements ApiEnum
{
    use ToleratesUnknownValues;

    case A = 'A';
    case Aaaa = 'AAAA';
    case Mx = 'MX';
    case Cname = 'CNAME';
    case Txt = 'TXT';
    case Ns = 'NS';

    /** A value this SDK version does not know; the raw payload keeps the original. */
    case Unrecognized = '__unrecognized__';
}
