<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use LIVCK\Cloud\Builders\Concerns\ConfiguresIpVersion;
use LIVCK\Cloud\Builders\Conditions\CustomCondition;
use LIVCK\Cloud\Builders\Conditions\TcpCondition;

/**
 * A TCP connect check of `host:port`. It has no default conditions: a refused or timed-out
 * connection is down by itself.
 */
final class TcpServiceBuilder extends MonitoredServiceBuilder
{
    use ConfiguresIpVersion;

    /**
     * Conditions on the connection; see {@see TcpCondition}. Appended to those set before.
     */
    public function condition(TcpCondition|CustomCondition ...$conditions): static
    {
        return $this->withConditions(...$conditions);
    }
}
