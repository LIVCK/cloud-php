<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Data\Concerns\ReadsMetricMaps;
use LIVCK\Cloud\Support\Field;

/**
 * The current figures of the server behind an `agent` service
 * (`GET /v1/services/{id}/agent-metrics`).
 *
 * `metrics` holds the latest value of every host key of the metric catalog, the keys conditions
 * use (`sys.cpu.total_pct`, `sys.mem.used_pct`, `sys.load.1`, …), null for a key the agent has
 * not reported within a day. The devices and the server's own checks come with their latest
 * values, keyed by sub-metric. A server that has not reported yet has nulls and empty lists, and
 * so has one whose figures cannot be read right now.
 */
final readonly class AgentMetrics
{
    use ReadsMetricMaps;

    /**
     * @param array<string, float|null> $metrics the latest value per catalog key
     * @param list<AgentDisk> $disks per mount
     * @param list<AgentGpu> $gpus per graphics card
     * @param list<AgentSmartDevice> $smart per drive with S.M.A.R.T. data
     * @param list<AgentProbe> $probes the checks the server runs itself (ports, URLs, hosts)
     * @param DateTimeImmutable|null $reportedAt when the server last reported a disk, GPU, drive or probe figure; null
     *                                         when it has not within the last hour
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public array $metrics,
        public array $disks,
        public array $gpus,
        public array $smart,
        public array $probes,
        public ?DateTimeImmutable $reportedAt,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data the `data` object of the response
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::metricMap($data, 'metrics'),
            array_map(AgentDisk::fromArray(...), Field::objectList($data, 'disks')),
            array_map(AgentGpu::fromArray(...), Field::objectList($data, 'gpus')),
            array_map(AgentSmartDevice::fromArray(...), Field::objectList($data, 'smart')),
            array_map(AgentProbe::fromArray(...), Field::objectList($data, 'probes')),
            Field::nullableInstant($data, 'reported_at'),
            $data,
        );
    }

    /** The latest value of one catalog key (`sys.cpu.total_pct`); null when it was not reported. */
    public function metric(string $key): ?float
    {
        return $this->metrics[$key] ?? null;
    }
}
