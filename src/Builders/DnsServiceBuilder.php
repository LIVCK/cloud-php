<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use LIVCK\Cloud\Builders\Conditions\CustomCondition;
use LIVCK\Cloud\Builders\Conditions\DnsCondition;
use LIVCK\Cloud\Enums\DnsRecordType;

/**
 * A DNS lookup. The record type is required; it decides which condition fields apply and
 * which default condition the server seeds (no record of that type is down; a `CNAME`
 * check relies on the lookup itself).
 */
final class DnsServiceBuilder extends MonitoredServiceBuilder
{
    /** The record type to resolve (`config.dns_type`). */
    public function recordType(DnsRecordType $type): static
    {
        return $this->withConfigValue('dns_type', self::wireValue($type));
    }

    /**
     * Conditions on the lookup; see {@see DnsCondition} for which fields the record type
     * produces. Appended to those set before.
     */
    public function condition(DnsCondition|CustomCondition ...$conditions): static
    {
        return $this->withConditions(...$conditions);
    }
}
