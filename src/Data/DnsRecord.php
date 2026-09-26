<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

/**
 * One DNS record the owner of a custom domain has to publish, ready to show to an end
 * customer or to hand to a DNS provider's API.
 *
 * `name` is fully qualified and has no trailing dot; zone editors that expect a name
 * relative to the zone need the zone suffix cut off.
 */
final readonly class DnsRecord
{
    public const string CNAME = 'CNAME';

    public const string TXT = 'TXT';

    /**
     * @param string $type {@see self::CNAME} or {@see self::TXT}
     */
    public function __construct(
        public string $type,
        public string $name,
        public string $value,
    ) {}

    public function isCname(): bool
    {
        return $this->type === self::CNAME;
    }

    public function isTxt(): bool
    {
        return $this->type === self::TXT;
    }
}
