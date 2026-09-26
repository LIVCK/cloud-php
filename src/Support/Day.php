<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use Stringable;

/**
 * A calendar day (`2026-08-01`) as opposed to an instant.
 *
 * Some values of the API are days, not points in time: the per-day uptime figures, for
 * instance, are keyed by `YYYY-MM-DD` without a timezone. Keeping them apart from
 * {@see DateTimeImmutable} prevents a day from being shifted across midnight by a
 * timezone conversion. Where an instant is needed, {@see startOfDayUtc()} is explicit
 * about the choice.
 */
final readonly class Day implements JsonSerializable, Stringable
{
    private function __construct(
        public int $year,
        public int $month,
        public int $day,
    ) {}

    public static function of(int $year, int $month, int $day): self
    {
        if (! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(sprintf('%04d-%02d-%02d is not a calendar day.', $year, $month, $day));
        }

        return new self($year, $month, $day);
    }

    /**
     * @param string $value `YYYY-MM-DD`
     */
    public static function fromString(string $value): self
    {
        $parsed = self::tryFromString($value);

        if (!$parsed instanceof Day) {
            throw new InvalidArgumentException(sprintf('"%s" is not a calendar day in the form YYYY-MM-DD.', $value));
        }

        return $parsed;
    }

    public static function tryFromString(string $value): ?self
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', trim($value), $matches) !== 1) {
            return null;
        }

        [, $year, $month, $day] = $matches;

        return checkdate((int) $month, (int) $day, (int) $year) ? new self((int) $year, (int) $month, (int) $day) : null;
    }

    /** The day an instant falls on in the given timezone (UTC by default). */
    public static function fromDateTime(DateTimeInterface $instant, ?DateTimeZone $timezone = null): self
    {
        $local = DateTimeImmutable::createFromInterface($instant)->setTimezone($timezone ?? new DateTimeZone('UTC'));

        return new self((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
    }

    /** Midnight at the start of this day, in UTC. */
    public function startOfDayUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->toString() . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public function isBefore(self $other): bool
    {
        return $this->toString() < $other->toString();
    }

    public function isAfter(self $other): bool
    {
        return $this->toString() > $other->toString();
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    /** `YYYY-MM-DD`, the API's form. */
    public function toString(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
