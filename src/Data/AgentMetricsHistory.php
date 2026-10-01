<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Data\Concerns\ReadsMetricMaps;
use LIVCK\Cloud\Support\Field;

/**
 * The course of a server's figures over a window (`GET /v1/services/{id}/agent-metrics/history`),
 * the way the console charts it: the window split into a few hundred buckets, with the average
 * and the peak of each key per bucket, and the key's figures over the whole window.
 *
 * Keys are the flat keys of the metric catalog, as conditions and the current figures use them:
 * `sys.cpu.total_pct`, `sys.disk._root.used_pct`, `sys.net.eth0.rx_bps`. Without a choice of
 * keys, the first 100 keys the server reported in the window are there, the host's own figures
 * first; `availableKeys` names all of them.
 */
final readonly class AgentMetricsHistory
{
    use ReadsMetricMaps;

    /**
     * @param int $windowSeconds the window the buckets cover, ending now; shorter than asked when the plan keeps
     *                           data for less time
     * @param list<string> $availableKeys every key the server reported in the window, also those not asked for
     * @param list<DateTimeImmutable> $timestamps the start of each bucket, oldest first, UTC
     * @param array<string, AgentMetricSeries> $metrics per key: average and peak per bucket, aligned with $timestamps
     * @param array<string, AgentMetricStats> $stats per key: the figures over the whole window
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public int $windowSeconds,
        public array $availableKeys,
        public array $timestamps,
        public array $metrics,
        public array $stats,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data the `data` object of the response
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::int($data, 'window_seconds'),
            Field::stringList($data, 'available_keys'),
            self::instantList($data, 'timestamps'),
            array_map(AgentMetricSeries::fromArray(...), self::metricObjects($data, 'metrics')),
            array_map(AgentMetricStats::fromArray(...), self::metricObjects($data, 'stats')),
            $data,
        );
    }
}
