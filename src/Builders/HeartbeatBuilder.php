<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use LIVCK\Cloud\Enums\HeartbeatKind;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * One heartbeat of a {@see HeartbeatDeclaration}: the key your code calls it by, what it
 * watches, and when it pings.
 *
 *     HeartbeatBuilder::schedule('export:nightly', HeartbeatSchedule::cron('0 3 * * *', 'Europe/Berlin'))
 *         ->grace(300)
 *         ->maxDuration(3600);
 *     HeartbeatBuilder::queue('queue:default', HeartbeatSchedule::every(60));
 *
 * Immutable: each option returns a new builder. An option that is not set is left out of the
 * request, and LIVCK keeps the value the heartbeat has (or its default, for a new one): grace
 * 60 seconds, a tolerance of 2 missed pings when the heartbeat is expected at least every five
 * minutes and 1 otherwise, no limit on the run time, the key as name.
 *
 * Nothing the server may accept tomorrow needs a new SDK release: {@see attribute()} writes any
 * key of the entry.
 */
final readonly class HeartbeatBuilder
{
    /**
     * @param array<string, mixed> $attributes
     */
    private function __construct(
        private string $key,
        private array $attributes,
    ) {}

    /** A scheduled task, such as a command run by cron or a framework scheduler. */
    public static function schedule(string $key, HeartbeatSchedule $schedule): self
    {
        return self::of(HeartbeatKind::Schedule, $key, $schedule);
    }

    /** A job that runs on its own, such as a backup or an import. */
    public static function job(string $key, HeartbeatSchedule $schedule): self
    {
        return self::of(HeartbeatKind::Job, $key, $schedule);
    }

    /** A queue worker that pings while it processes. */
    public static function queue(string $key, HeartbeatSchedule $schedule): self
    {
        return self::of(HeartbeatKind::Queue, $key, $schedule);
    }

    /** Anything else that reports in. */
    public static function custom(string $key, HeartbeatSchedule $schedule): self
    {
        return self::of(HeartbeatKind::Custom, $key, $schedule);
    }

    /**
     * A heartbeat of the given kind.
     *
     * @param string $key what your code calls the heartbeat, e.g. `export:nightly`; case-sensitive.
     *                    Letters, digits, `.`, `_`, `-`, `:` and `/`, starting and ending with a
     *                    letter or digit (checked by the server)
     *
     * @throws InvalidArgumentException for a blank key or an unrecognized kind
     */
    public static function of(HeartbeatKind $kind, string $key, HeartbeatSchedule $schedule): self
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A heartbeat key must not be blank.');
        }

        if ($kind->isUnrecognized()) {
            throw new InvalidArgumentException('HeartbeatKind::Unrecognized cannot be sent; pick a kind.');
        }

        return new self($key, ['key' => $key, 'kind' => $kind->value, 'schedule' => $schedule->toArray()]);
    }

    /**
     * The name shown in LIVCK. Used when the heartbeat is created only; a later change here never
     * renames it, so a name given in the console stays.
     */
    public function name(string $name): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A heartbeat name must not be blank; leave it out to use the key.');
        }

        return $this->with('name', $name);
    }

    /** Seconds a ping may be late before it counts as missed. */
    public function grace(int $seconds): self
    {
        return $this->with('grace_seconds', $seconds);
    }

    /** How many expected pings in a row must be missed before the heartbeat is down. */
    public function tolerance(int $missedPings): self
    {
        return $this->with('schedule_tolerance', $missedPings);
    }

    /**
     * The longest a run may take between its start ping and its end ping. Without this call (and
     * without {@see noMaxDuration()}) a limit set earlier stays as it is.
     */
    public function maxDuration(int $seconds): self
    {
        return $this->with('max_duration_seconds', $seconds);
    }

    /** Remove the limit on the run time: sends `max_duration_seconds: null`. */
    public function noMaxDuration(): self
    {
        return $this->with('max_duration_seconds', null);
    }

    /**
     * Any key of the entry, sent as given; overrides a typed value of the same key. The key of
     * the heartbeat itself (`key`) is set by the factory and cannot be replaced here.
     */
    public function attribute(string $key, mixed $value): self
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A key must not be blank.');
        }

        if ($key === 'key') {
            throw new InvalidArgumentException('The heartbeat key is set by the factory; build a new heartbeat for another key.');
        }

        return $this->with($key, $value);
    }

    /** The key the heartbeat is declared under. */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    private function with(string $field, mixed $value): self
    {
        return new self($this->key, [...$this->attributes, $field => $value]);
    }
}
