<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Fixtures;

/**
 * `GET /v1/me` and `GET /v1/probes` as the server writes them.
 */
final class DiscoveryFixtures
{
    /**
     * An organization token; `service` and `expires_at` are absent for one that is not
     * bound to an agent and never expires.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function me(array $overrides = []): array
    {
        return [
            'type' => 'user',
            'permissions' => ['services.view', 'incidents.view', 'maintenances.view'],
            'organization' => ['public_id' => 'nquKGB2qGm1X7pp60tUHZ', 'name' => 'Example Hosting'],
            'rate_limit' => ['requests_per_minute' => 120],
            ...$overrides,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function probes(): array
    {
        return [
            ['code' => 'ffm', 'name' => 'Frankfurt', 'location' => 'Germany', 'country_code' => 'DE'],
            ['code' => 'hel', 'name' => 'Helsinki', 'location' => 'Finland', 'country_code' => 'FI'],
            ['code' => 'nyc', 'name' => 'New York', 'location' => 'USA (New York)', 'country_code' => 'US'],
        ];
    }
}
