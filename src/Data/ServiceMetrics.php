<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Enums\MetricsRange;
use LIVCK\Cloud\Support\Field;

/**
 * Aggregated figures for a range (`GET /v1/services/{id}/metrics`): availability in
 * percent, latency percentiles in milliseconds and check counts. Every figure but the
 * counts is null when nothing was measured in the range.
 */
final readonly class ServiceMetrics
{
    /**
     * @param float|null $uptime availability in the range, in percent (0–100); null until the
     *                          service has been observed (its first check was processed) and
     *                          for a range that lies entirely inside maintenance
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?float $uptime,
        public ?float $avgMs,
        public ?float $p95Ms,
        public ?float $p99Ms,
        public ?float $minMs,
        public ?float $maxMs,
        public int $totalChecks,
        public int $failedChecks,
        public MetricsRange $range,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data the `data` block
     * @param MetricsRange $range the range the server reported in `meta.range`
     */
    public static function fromArray(array $data, MetricsRange $range): self
    {
        return new self(
            Field::nullableFloat($data, 'uptime'),
            Field::nullableFloat($data, 'avg_ms'),
            Field::nullableFloat($data, 'p95_ms'),
            Field::nullableFloat($data, 'p99_ms'),
            Field::nullableFloat($data, 'min_ms'),
            Field::nullableFloat($data, 'max_ms'),
            Field::int($data, 'total_checks'),
            Field::int($data, 'failed_checks'),
            $range,
            $data,
        );
    }

    public function hasData(): bool
    {
        return $this->totalChecks > 0;
    }
}
