<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Data\Concerns\ReadsMetricMaps;
use LIVCK\Cloud\Support\Field;

/**
 * The latest figures of one graphics card of a server.
 */
final readonly class AgentGpu
{
    use ReadsMetricMaps;

    /**
     * @param string $id the card as the metric keys name it (its PCI address)
     * @param string|null $name the model, when the agent reported one
     * @param array<string, float> $metrics latest value per sub-metric
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public array $metrics,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Field::string($data, 'id'), Field::nullableString($data, 'name'), self::numberMap($data, 'metrics'), $data);
    }
}
