<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Concerns;

use LIVCK\Cloud\Enums\IpVersion;

/**
 * The address-family options every network check shares (`config.ip_version`,
 * `config.smart_dualstack`).
 */
trait ConfiguresIpVersion
{
    /**
     * Which address family to connect over. `auto` (the default) tries both and reports up
     * as soon as either answers; `ipv4`/`ipv6` force one family with no fallback.
     */
    public function ipVersion(IpVersion $version): static
    {
        return $this->withConfigValue('ip_version', self::wireValue($version));
    }

    /**
     * With `auto`: report `degraded` instead of `up` when the host offers both families
     * but only one is reachable.
     */
    public function smartDualstack(bool $enabled = true): static
    {
        return $this->withConfigValue('smart_dualstack', $enabled);
    }
}
