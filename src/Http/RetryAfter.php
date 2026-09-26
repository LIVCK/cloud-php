<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Http;

use DateTimeImmutable;
use DateTimeZone;

/**
 * `Retry-After` header values: delta-seconds (`30`) or an HTTP-date in one of the three
 * RFC 9110 forms (IMF-fixdate, RFC 850, asctime).
 */
final class RetryAfter
{
    private const array DATE_FORMATS = [
        DATE_RFC7231,            // Sun, 06 Nov 1994 08:49:37 GMT
        'l, d-M-y H:i:s T',      // Sunday, 06-Nov-94 08:49:37 GMT
        'D M j H:i:s Y',         // Sun Nov  6 08:49:37 1994
    ];

    /**
     * Seconds to wait, never negative; null when the value is neither a number nor a date.
     */
    public static function seconds(string $value, DateTimeImmutable $now): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $date = self::httpDate($value);

        return $date instanceof DateTimeImmutable ? max(0, $date->getTimestamp() - $now->getTimestamp()) : null;
    }

    private static function httpDate(string $value): ?DateTimeImmutable
    {
        $value = (string) preg_replace('/\s+/', ' ', $value);

        foreach (self::DATE_FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));

            if ($parsed !== false) {
                return $parsed;
            }
        }

        return null;
    }
}
