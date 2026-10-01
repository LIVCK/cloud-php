<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Data\Concerns\ReadsMetricMaps;
use LIVCK\Cloud\Support\Field;

/**
 * The latest S.M.A.R.T. figures of one drive of a server.
 */
final readonly class AgentSmartDevice
{
    use ReadsMetricMaps;

    /**
     * @param string $device the drive as the metric keys name it
     * @param array<string, float> $metrics latest value per sub-metric
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $device,
        public array $metrics,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Field::string($data, 'device'), self::numberMap($data, 'metrics'), $data);
    }
}
