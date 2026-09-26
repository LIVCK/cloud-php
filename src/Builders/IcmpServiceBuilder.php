<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use LIVCK\Cloud\Builders\Concerns\ConfiguresIpVersion;
use LIVCK\Cloud\Builders\Conditions\CustomCondition;
use LIVCK\Cloud\Builders\Conditions\IcmpCondition;

/**
 * An ICMP echo (ping) check. Without conditions the server applies its default (any
 * packet loss is degraded).
 */
final class IcmpServiceBuilder extends MonitoredServiceBuilder
{
    use ConfiguresIpVersion;

    /**
     * Conditions on the echo replies; see {@see IcmpCondition}. Appended to those set before.
     */
    public function condition(IcmpCondition|CustomCondition ...$conditions): static
    {
        return $this->withConditions(...$conditions);
    }
}
