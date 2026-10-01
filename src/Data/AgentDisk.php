<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Data\Concerns\ReadsMetricMaps;
use LIVCK\Cloud\Support\Field;

/**
 * The latest figures of one mounted file system of a server.
 */
final readonly class AgentDisk
{
    use ReadsMetricMaps;

    /**
     * @param string $mount the mount as the metric keys name it (`_root` for `/`)
     * @param array<string, float> $metrics latest value per sub-metric (`used_pct`, `used_bytes`, `total_bytes`, …)
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $mount,
        public array $metrics,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Field::string($data, 'mount'), self::numberMap($data, 'metrics'), $data);
    }
}
