<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Instants on the wire.
 *
 * The API writes ISO 8601 in UTC (`2026-08-01T12:00:00Z`, `...+00:00`, with or without
 * a fraction) and reads the same grammar back, UTC unless an offset is given. Parsing is
 * tolerant within that grammar and always yields a UTC {@see DateTimeImmutable};
 * formatting always writes `Z`, keeping a fraction only when it is not zero.
 */
final class Dates
{
    /**
     * A calendar date, optionally a time (`T` or space), optional seconds with a fraction of
     * up to nine digits, optional `Z` or `±HH[:MM]` offset. Anchored with \z so a trailing
     * newline cannot pass.
     */
    private const string PATTERN = '/^(?<date>\d{4}-\d{2}-\d{2})(?:[Tt ](?<time>\d{2}:\d{2}(?::\d{2})?)(?:\.(?<fraction>\d{1,9}))?)?(?<offset>[Zz]|[+-]\d{2}(?::?\d{2})?)?\z/';

    /**
     * Parse an instant; null stays null.
     *
     * @throws InvalidArgumentException when the value is not an ISO 8601 instant
     */
    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $parsed = self::tryParse($value);

        if (!$parsed instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(sprintf('"%s" is not an ISO 8601 date or date-time.', $value));
        }

        return $parsed;
    }

    /**
     * Parse an instant, null when the value is not one.
     */
    public static function tryParse(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            return null;
        }

        $time = ($matches['time'] ?? '') !== '' ? $matches['time'] : '00:00:00';

        if (strlen($time) === 5) {
            $time .= ':00';
        }

        $fraction = substr(str_pad($matches['fraction'] ?? '', 6, '0'), 0, 6);
        $offset = self::normalizeOffset($matches['offset'] ?? '');

        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u P',
            sprintf('%s %s.%s %s', $matches['date'], $time, $fraction, $offset),
            new DateTimeZone('UTC'),
        );

        // createFromFormat() rolls an impossible date (February 30th) over with a warning
        // instead of failing; a bound naming a day that does not exist is a mistake.
        $errors = DateTimeImmutable::getLastErrors();

        if ($instant === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * ISO 8601 in UTC with `Z`; the fraction is kept only when it carries information.
     */
    public static function format(DateTimeInterface $value): string
    {
        $utc = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        $fraction = rtrim($utc->format('u'), '0');

        return $utc->format('Y-m-d\TH:i:s') . ($fraction === '' ? '' : '.' . $fraction) . 'Z';
    }

    private static function normalizeOffset(string $offset): string
    {
        if (in_array($offset, ['', 'Z', 'z'], true)) {
            return '+00:00';
        }

        $sign = $offset[0];
        $digits = str_replace(':', '', substr($offset, 1));

        return $sign . substr($digits, 0, 2) . ':' . (strlen($digits) === 4 ? substr($digits, 2, 2) : '00');
    }
}
