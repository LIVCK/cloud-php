<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Data\Concerns\ReadsMetricMaps;

/**
 * One key's course over the buckets of an {@see AgentMetricsHistory}: the average and the peak
 * of each bucket, aligned with its timestamps, null for a bucket without a report. Both lists
 * are empty for a key that was asked for and has no report in the window.
 */
final readonly class AgentMetricSeries
{
    use ReadsMetricMaps;

    /**
     * @param list<float|null> $avg the average per bucket
     * @param list<float|null> $max the peak per bucket
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public array $avg,
        public array $max,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(self::metricList($data, 'avg'), self::metricList($data, 'max'), $data);
    }
}
