<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Data\Concerns\ReadsMetricMaps;
use LIVCK\Cloud\Support\Field;

/**
 * A check the server runs itself (a port, a URL, a host it pings), with its latest reading.
 */
final readonly class AgentProbe
{
    use ReadsMetricMaps;

    /**
     * @param string $type how it is checked, as configured: `tcp`, `http`, `https` or `dns`
     * @param int|null $port null for a check without a port
     * @param bool|null $up whether the last reading succeeded; null before the first one
     * @param array<string, float> $metrics latest value per sub-metric (`latency_ms`, `rtt_avg_ms`, …); empty before the
     *                                    first reading
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $type,
        public string $target,
        public ?int $port,
        public ?bool $up,
        public array $metrics,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'id'),
            Field::string($data, 'label'),
            Field::string($data, 'type'),
            Field::string($data, 'target'),
            Field::nullableInt($data, 'port'),
            Field::nullableBool($data, 'up'),
            self::numberMap($data, 'metrics'),
            $data,
        );
    }
}
