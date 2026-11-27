<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use LIVCK\Cloud\Enums\HeartbeatScheduleType;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * When a declared heartbeat expects its pings:
 *
 *     HeartbeatSchedule::cron('0 3 * * *', 'Europe/Berlin')   // whenever the expression is due
 *     HeartbeatSchedule::every(300)                            // every five minutes
 *
 * The server checks the values: a cron expression has five fields, a time zone is an IANA name,
 * and a period below the shortest of the plan is raised to it (the answer shows the value in
 * effect).
 */
final readonly class HeartbeatSchedule
{
    /**
     * @param array<string, int|string> $attributes
     */
    private function __construct(
        private array $attributes,
    ) {}

    /**
     * A ping whenever the expression is due.
     *
     * @param string $expression five fields: minute, hour, day of month, month, day of week
     * @param string|null $timezone IANA time zone the expression is read in, e.g. `Europe/Berlin`;
     *                              null leaves it as it is (UTC for a new heartbeat)
     */
    public static function cron(string $expression, ?string $timezone = null): self
    {
        if (trim($expression) === '') {
            throw new InvalidArgumentException('A cron expression must not be blank.');
        }

        $attributes = ['type' => HeartbeatScheduleType::Cron->value, 'expression' => $expression];

        if ($timezone !== null) {
            if (trim($timezone) === '') {
                throw new InvalidArgumentException('A time zone must not be blank; pass null to leave it as it is.');
            }

            $attributes['timezone'] = $timezone;
        }

        return new self($attributes);
    }

    /**
     * A ping every `$seconds`.
     */
    public static function every(int $seconds): self
    {
        return new self(['type' => HeartbeatScheduleType::Simple->value, 'period_seconds' => $seconds]);
    }

    public function type(): HeartbeatScheduleType
    {
        return HeartbeatScheduleType::from((string) $this->attributes['type']);
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
