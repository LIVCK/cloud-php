<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Enums\HeartbeatKind;
use LIVCK\Cloud\Support\Field;

/**
 * The application that declares a heartbeat from its code ({@see ServiceHeartbeat::$declared}):
 * the names it was declared under with `heartbeats()->declare()`.
 */
final readonly class HeartbeatOrigin
{
    /**
     * @param string $app the application, as the declaration names it (`shop`)
     * @param string $environment the environment, as the declaration names it (`production`)
     * @param string $key what the code calls the heartbeat (`export:nightly`)
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $app,
        public string $environment,
        public string $key,
        public HeartbeatKind $kind,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'app'),
            Field::string($data, 'environment'),
            Field::string($data, 'key'),
            HeartbeatKind::fromApi(Field::string($data, 'kind')),
            $data,
        );
    }
}
