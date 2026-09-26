<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use LIVCK\Cloud\Builders\Concerns\ConfiguresIpVersion;
use LIVCK\Cloud\Builders\Conditions\CustomCondition;
use LIVCK\Cloud\Builders\Conditions\SslCondition;
use Override;

/**
 * A certificate check. Certificates change rarely, so the default interval is six hours
 * and the server never runs it more often than hourly (at most weekly). Without
 * conditions the server applies its default (fewer than 14 days to expiry is degraded).
 */
final class SslServiceBuilder extends MonitoredServiceBuilder
{
    use ConfiguresIpVersion;

    /** Six hours: the type's own default (`GET /v1/meta/check-types`). */
    public const int DEFAULT_SSL_INTERVAL_SECONDS = 21600;

    /**
     * Conditions on the certificate; see {@see SslCondition}. Appended to those set before.
     */
    public function condition(SslCondition|CustomCondition ...$conditions): static
    {
        return $this->withConditions(...$conditions);
    }

    #[Override]
    protected function defaultIntervalSeconds(): int
    {
        return self::DEFAULT_SSL_INTERVAL_SECONDS;
    }
}
