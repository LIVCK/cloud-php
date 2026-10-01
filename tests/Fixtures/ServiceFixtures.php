<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Fixtures;

use LIVCK\Cloud\Support\JsonObject;
use LIVCK\Cloud\Support\KeepSecret;

/**
 * Services as `ServiceResource::toArray()` writes them, plus the metric envelopes of the
 * `/metrics`, `/uptime` and `/response-times` endpoints.
 */
final class ServiceFixtures
{
    public const string ID = 'iChkaXWKTdPJxp7dJaDwn';

    public const string TAG_ID = 'V1StGXR8Z5jdHi6BmyT01';

    public const string AGENT_ID = 'Ag3ntSrv1b2C3d4E5f6G7';

    /** Every host key of the metric catalog, as the current figures list them. */
    public const array HOST_METRIC_KEYS = [
        'sys.cpu.total_pct', 'sys.cpu.user_pct', 'sys.cpu.system_pct', 'sys.cpu.iowait_pct', 'sys.cpu.steal_pct',
        'sys.load.1', 'sys.load.5', 'sys.load.15',
        'sys.mem.total_bytes', 'sys.mem.used_bytes', 'sys.mem.available_bytes', 'sys.mem.used_pct', 'sys.mem.cached_bytes',
        'sys.mem.buffers_bytes', 'sys.mem.oom_kills',
        'sys.swap.total_bytes', 'sys.swap.used_bytes', 'sys.swap.used_pct',
        'sys.psi.cpu_some_pct', 'sys.psi.mem_some_pct', 'sys.psi.io_some_pct',
        'sys.uptime_seconds', 'sys.procs.total', 'sys.procs.zombies',
    ];

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
            'agent' => null,
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
     * An `agent` service: a server that enrolled with an enrollment key and has reported. Its
     * target is the host name, its settings carry the interval and an empty config, which the
     * server writes as `[]`.
     *
     * @param array<string, mixed> $agent overrides of the agent block
     * @param array<string, mixed> $overrides overrides of the service
     * @return array<string, mixed>
     */
    public static function agentPayload(array $agent = [], array $overrides = []): array
    {
        return self::payload([
            'id' => self::AGENT_ID,
            'name' => 'web-1',
            'check_type' => 'agent',
            'check_type_label' => 'Server Agent',
            'target' => 'web-1',
            'uptime_30d' => 100,
            'avg_response_ms' => null,
            'tags' => [
                self::tag(),
                ['id' => 'Sy5tEmT4g1b2C3d4E5f6G', 'key' => 'os', 'value' => 'debian', 'color' => '#64748b', 'label' => 'os:debian', 'source' => 'system'],
            ],
            'last_check_at' => '2026-10-01T11:59:30+00:00',
            'settings' => self::settings(['assigned_probes' => null, 'probe_roles' => null, 'config' => []]),
            'agent' => self::agent($agent),
            ...$overrides,
        ]);
    }

    /**
     * The agent block of an `agent` service, as `ServiceAgentResource::toArray()` writes it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function agent(array $overrides = []): array
    {
        return [
            'state' => 'online',
            'state_changed_at' => '2026-10-01T08:00:00+00:00',
            'last_seen_at' => '2026-10-01T11:59:30+00:00',
            'hostname' => 'web-1',
            'version' => '1.4.0',
            'os' => 'linux',
            'distro' => 'debian',
            'distro_version' => '12',
            'kernel' => '6.1.0-26-amd64',
            'arch' => 'amd64',
            'virtualization' => 'kvm',
            'cpu_model' => 'AMD EPYC-Rome Processor',
            'cpu_cores' => 8,
            'ram_total_bytes' => 17179869184,
            'booted_at' => '2026-09-21T14:13:20+00:00',
            'reboot_required' => false,
            'ips' => [
                'private' => ['10.0.0.5'],
                'public' => ['203.0.113.7', '2001:db8::7'],
                'observed' => [
                    ['ip' => '203.0.113.7', 'family' => 'v4', 'at' => '2026-10-01T11:58:00+00:00'],
                    ['ip' => '2001:db8::7', 'family' => 'v6', 'at' => null],
                ],
            ],
            'update' => [
                'automatic' => true,
                'window_start' => '00:00',
                'window_end' => '04:00',
                'available_version' => '1.5.0',
            ],
            'instance_conflict_at' => null,
            'enrollment_key_id' => EnrollmentKeyFixtures::ID,
            ...$overrides,
        ];
    }

    /**
     * `GET /v1/services/{id}/agent-metrics`: CPU and memory reported, swap never; one disk, one
     * check the server runs itself and one that has not reported yet. Whole numbers arrive
     * without a fraction; an empty map as `{}`.
     *
     * @return array<string, mixed>
     */
    public static function agentMetrics(): array
    {
        return ['data' => self::agentMetricsData()];
    }

    /**
     * The `data` of {@see agentMetrics()}.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function agentMetricsData(array $overrides = []): array
    {
        $metrics = array_fill_keys(self::HOST_METRIC_KEYS, null);
        $metrics['sys.cpu.total_pct'] = 12.5;
        $metrics['sys.mem.used_pct'] = 40;
        $metrics['sys.load.1'] = 0.42;

        return [
            'metrics' => $metrics,
            'disks' => [['mount' => '_root', 'metrics' => ['total_bytes' => 107374182400, 'used_bytes' => 76235669504, 'used_pct' => 71]]],
            'gpus' => [['id' => '00000000_01_00.0', 'name' => 'NVIDIA L4', 'metrics' => ['temp_c' => 54, 'util_pct' => 12.5]]],
            'smart' => [['device' => 'nvme0n1', 'metrics' => ['healthy' => 1, 'temp_c' => 38]]],
            'probes' => [
                ['id' => 'k3j9x2', 'label' => 'nginx', 'type' => 'tcp', 'target' => '127.0.0.1', 'port' => 443, 'up' => true, 'metrics' => ['latency_ms' => 0.4, 'up' => 1]],
                ['id' => 'p8q1w7', 'label' => 'Upstream DNS', 'type' => 'dns', 'target' => 'example.com', 'port' => null, 'up' => null, 'metrics' => JsonObject::empty()],
            ],
            'reported_at' => '2026-10-01T11:59:30Z',
            ...$overrides,
        ];
    }

    /**
     * The `data` of `GET /v1/services/{id}/agent-metrics` for a server that has not reported yet.
     *
     * @return array<string, mixed>
     */
    public static function agentMetricsBeforeFirstReport(): array
    {
        return [
            'metrics' => array_fill_keys(self::HOST_METRIC_KEYS, null),
            'disks' => [],
            'gpus' => [],
            'smart' => [],
            'probes' => [],
            'reported_at' => null,
        ];
    }

    /**
     * `GET /v1/services/{id}/agent-metrics/history`: three buckets of a day's window; CPU in all of
     * them, the root disk not in the first. Whole numbers arrive without a fraction.
     *
     * @return array<string, mixed>
     */
    public static function agentMetricsHistory(int $windowSeconds = 86400): array
    {
        return ['data' => self::agentMetricsHistoryData(['window_seconds' => $windowSeconds])];
    }

    /**
     * The `data` of {@see agentMetricsHistory()}.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function agentMetricsHistoryData(array $overrides = []): array
    {
        return [
            'window_seconds' => 86400,
            'timestamps' => ['2026-10-01T09:00:00Z', '2026-10-01T09:06:00Z', '2026-10-01T09:12:00Z'],
            'metrics' => [
                'sys.cpu.total_pct' => ['avg' => [12.5, 30, 18.25], 'max' => [20.0, 64.5, 31]],
                'sys.disk._root.used_pct' => ['avg' => [null, 71, 71.2], 'max' => [null, 71, 71.4]],
            ],
            'stats' => [
                'sys.cpu.total_pct' => ['last' => 17.5, 'min' => 3.25, 'avg' => 20.25, 'max' => 64.5, 'p50' => 18, 'p95' => 52.5, 'p99' => 63.75, 'samples' => 1440],
                'sys.disk._root.used_pct' => ['last' => 71.4, 'min' => 71, 'avg' => 71.1, 'max' => 71.4, 'p50' => 71.1, 'p95' => 71.4, 'p99' => 71.4, 'samples' => 960],
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
