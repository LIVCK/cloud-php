<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Data\Concerns\ReadsMetricMaps;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;

/**
 * One key's figures over the whole window of an {@see AgentMetricsHistory}. Every figure is null
 * when the key was not reported in the window.
 */
final readonly class AgentMetricStats
{
    use ReadsMetricMaps;

    /**
     * @param float|null $last the latest value in the window
     * @param int $samples how many samples the figures are drawn from
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public ?float $last,
        public ?float $min,
        public ?float $avg,
        public ?float $max,
        public ?float $p50,
        public ?float $p95,
        public ?float $p99,
        public int $samples,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::nullableMetric($data, 'last'),
            self::nullableMetric($data, 'min'),
            self::nullableMetric($data, 'avg'),
            self::nullableMetric($data, 'max'),
            self::nullableMetric($data, 'p50'),
            self::nullableMetric($data, 'p95'),
            self::nullableMetric($data, 'p99'),
            self::count($data['samples'] ?? null),
            $data,
        );
    }

    /** A count from the metrics store, which writes 64-bit integers quoted. */
    private static function count(mixed $value): int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/\A\d+\z/', $value) === 1 => (int) $value,
            default => throw new UnexpectedResponseException(sprintf('Field "samples": expected integer, got %s.', get_debug_type($value))),
        };
    }

    public function hasData(): bool
    {
        return $this->samples > 0;
    }
}
