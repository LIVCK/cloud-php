<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Enums\HeartbeatScheduleType;
use LIVCK\Cloud\Support\Field;

/**
 * The schedule a heartbeat runs on, as it is in effect: a period below the plan's shortest is
 * raised to it, and a field the declaration left out keeps its value. The answer to a
 * declaration ({@see DeclaredHeartbeat::$schedule}) and a `heartbeat` service
 * ({@see ServiceHeartbeat::$schedule}) report it alike.
 */
final readonly class DeclaredSchedule
{
    /**
     * @param int|null $periodSeconds seconds between two pings for `simple`, null for `cron`
     * @param string|null $expression the cron expression for `cron`, null for `simple`
     * @param string $timezone IANA time zone the expression is read in (`UTC` unless one was set)
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public HeartbeatScheduleType $type,
        public ?int $periodSeconds,
        public ?string $expression,
        public string $timezone,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            HeartbeatScheduleType::fromApi(Field::string($data, 'type')),
            Field::nullableInt($data, 'period_seconds'),
            Field::nullableString($data, 'expression'),
            Field::string($data, 'timezone'),
            $data,
        );
    }

    public function isCron(): bool
    {
        return $this->type === HeartbeatScheduleType::Cron;
    }
}
