<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Fixtures;

use LIVCK\Cloud\Support\KeepSecret;

/**
 * Services as `ServiceResource::toArray()` writes them, plus the metric envelopes of the
 * `/metrics`, `/uptime` and `/response-times` endpoints.
 */
final class ServiceFixtures
{
    public const string ID = 'iChkaXWKTdPJxp7dJaDwn';

    public const string TAG_ID = 'V1StGXR8Z5jdHi6BmyT01';

    /**
     * A configured HTTP service with one tag; secrets masked as the server masks them.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        return [
            'id' => self::ID,
            'name' => 'API health',
            'check_type' => 'http',
            'check_type_label' => 'HTTP/HTTPS',
            'target' => 'https://api.example.com/health',
            'status' => 'up',
            'effective_status' => 'up',
            'status_override' => null,
            'status_override_reason' => null,
            'status_override_at' => null,
            'favicon_url' => null,
            'is_paused' => false,
            'paused_reason' => null,
            'configured_at' => '2026-08-14T02:40:55+00:00',
            'is_configured' => true,
            'uptime_30d' => 99.98,
            'avg_response_ms' => 142.5,
            'tags' => [self::tag()],
            'last_check_at' => '2026-09-26T05:01:35+00:00',
            'created_at' => '2026-08-14T02:40:55+00:00',
            'settings' => self::settings(),
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function settings(array $overrides = []): array
    {
        return [
            'interval_seconds' => 60,
            'timeout_seconds' => 10,
            'retries' => 2,
            'assigned_probes' => ['ffm', 'hel'],
            'probe_roles' => ['hel' => 'reachability'],
            'config' => [
                'method' => 'GET',
                'headers' => ['X-Api-Key' => KeepSecret::SENTINEL],
                'auth' => ['type' => 'bearer', 'token' => KeepSecret::SENTINEL],
                'body' => '',
                'follow_redirects' => true,
                'verify_ssl' => true,
                'ip_version' => 'auto',
                'smart_dualstack' => false,
                'conditions' => [
                    ['field' => 'status_code', 'operator' => 'gte', 'value' => 400, 'status' => 'down'],
                ],
            ],
            ...$overrides,
        ];
    }

    /**
     * A service created without settings: it exists but is not monitored.
     *
     * @return array<string, mixed>
     */
    public static function unconfigured(): array
    {
        return self::payload([
            'id' => '4xYd0WPRhKDrDd2xPKRfe',
            'name' => 'New service',
            'status' => 'unknown',
            'effective_status' => 'unknown',
            'configured_at' => null,
            'is_configured' => false,
            'uptime_30d' => null,
            'avg_response_ms' => null,
            'tags' => [],
            'last_check_at' => null,
            'settings' => null,
        ]);
    }

    /**
     * An embedded tag as the service payload carries it (no `services_count`).
     *
     * @return array<string, mixed>
     */
    public static function tag(): array
    {
        return [
            'id' => self::TAG_ID,
            'key' => 'kunde',
            'value' => '4711',
            'color' => '#6366f1',
            'label' => 'kunde:4711',
            'source' => 'user',
        ];
    }

    /**
     * `GET /v1/services/{id}/metrics`.
     *
     * @return array<string, mixed>
     */
    public static function metrics(string $range = '24h'): array
    {
        return [
            'data' => [
                'uptime' => 99.95,
                'avg_ms' => 142.5,
                'p95_ms' => 310.0,
                'p99_ms' => 512.2,
                'min_ms' => 88,
                'max_ms' => 1204,
                'total_checks' => 2880,
                'failed_checks' => 3,
            ],
            'meta' => ['range' => $range],
        ];
    }

    /**
     * `GET /v1/services/{id}/uptime` for three days, the first without data.
     *
     * @return array<string, mixed>
     */
    public static function uptime(): array
    {
        return [
            'data' => [
                ['date' => '2026-09-24', 'status' => 'no_data', 'uptime_percent' => 0, 'incidents' => 0],
                ['date' => '2026-09-25', 'status' => 'degraded', 'uptime_percent' => 97.5, 'incidents' => 1],
                ['date' => '2026-09-26', 'status' => 'up', 'uptime_percent' => 100, 'incidents' => 0],
            ],
            'meta' => ['days' => 3],
        ];
    }

    /**
     * `GET /v1/services/{id}/response-times`: two hourly buckets as the metrics store
     * returns them, whole milliseconds for the extremes and one decimal for the averages
     * and percentiles.
     *
     * @return array<string, mixed>
     */
    public static function responseTimes(string $range = '24h'): array
    {
        return [
            'data' => [
                ['timestamp' => '2026-09-26T04:00:00Z', 'avg_ms' => 140.2, 'min_ms' => 90, 'max_ms' => 410, 'p95_ms' => 300.5, 'p99_ms' => 405.0],
                ['timestamp' => '2026-09-26T05:00:00Z', 'avg_ms' => 151.7, 'min_ms' => 95, 'max_ms' => 388, 'p95_ms' => 320.1, 'p99_ms' => 480.9],
            ],
            'meta' => ['range' => $range],
        ];
    }
}
