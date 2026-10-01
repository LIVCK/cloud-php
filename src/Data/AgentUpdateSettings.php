<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * How a server agent updates itself.
 */
final readonly class AgentUpdateSettings
{
    /**
     * @param bool $automatic whether the agent updates itself
     * @param string $windowStart start of the window automatic updates run in, `HH:MM` in the host's local time
     * @param string $windowEnd end of that window, `HH:MM`
     * @param string|null $availableVersion the version the agent is meant to move to; null while it runs it already
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public bool $automatic,
        public string $windowStart,
        public string $windowEnd,
        public ?string $availableVersion,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::bool($data, 'automatic'),
            Field::string($data, 'window_start'),
            Field::string($data, 'window_end'),
            Field::nullableString($data, 'available_version'),
            $data,
        );
    }
}
