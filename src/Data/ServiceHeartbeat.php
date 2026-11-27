<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Support\Field;

/**
 * The schedule of a `heartbeat` service and when it last pinged ({@see Service::$heartbeat}).
 *
 * `declared` names the application that declares the heartbeat from its code, and is null for
 * a heartbeat created by hand. The ping URL is never part of a service: it is handed out once,
 * by the declaration ({@see DeclaredHeartbeat::$pingUrl}) or in the console.
 */
final readonly class ServiceHeartbeat
{
    /**
     * @param DeclaredSchedule $schedule `periodSeconds` for `simple`, `expression` for `cron`, the other one null
     * @param int $graceSeconds how long LIVCK waits past a due ping before it raises an incident
     * @param int $scheduleTolerance how many due pings may be missed before it does
     * @param int|null $maxDurationSeconds longest run between start and end ping, null without a limit
     * @param DateTimeImmutable|null $lastPingAt null until the first ping
     * @param HeartbeatOrigin|null $declared the application that declares the heartbeat; null for one created by hand
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public DeclaredSchedule $schedule,
        public int $graceSeconds,
        public int $scheduleTolerance,
        public ?int $maxDurationSeconds,
        public ?DateTimeImmutable $lastPingAt,
        public ?HeartbeatOrigin $declared,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $declared = Field::nullableObject($data, 'declared');

        return new self(
            DeclaredSchedule::fromArray(Field::object($data, 'schedule')),
            Field::int($data, 'grace_seconds'),
            Field::int($data, 'schedule_tolerance'),
            Field::nullableInt($data, 'max_duration_seconds'),
            Field::nullableInstant($data, 'last_ping_at'),
            $declared === null ? null : HeartbeatOrigin::fromArray($declared),
            $data,
        );
    }

    /** Whether an application declares this heartbeat from its code. */
    public function isDeclared(): bool
    {
        return $this->declared instanceof HeartbeatOrigin;
    }
}
