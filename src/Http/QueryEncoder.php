<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Http;

use BackedEnum;
use DateTimeInterface;
use LIVCK\Cloud\Enums\ApiEnum;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Dates;
use Stringable;

/**
 * Query strings the way the API reads them.
 *
 *  - lists become repeated `name[]=a&name[]=b` (the form every v1 list filter accepts);
 *    an EMPTY list is sent as `name=` (explicitly nothing: for a scope filter such as
 *    `service_ids` that means "match nothing", never "no filter");
 *  - nested objects become `name[key]=value`;
 *  - booleans are `1`/`0`: Laravel's `boolean` rule rejects the strings "true"/"false";
 *  - instants are ISO 8601 UTC with `Z`, fraction only when non-zero; a
 *    {@see \LIVCK\Cloud\Support\Day} is `YYYY-MM-DD`;
 *  - backed enums send their value, an enum's `Unrecognized` case is refused;
 *  - null values are omitted, so an unset filter is an absent parameter.
 *
 * Names and values are percent-encoded per RFC 3986 (a space is `%20`, brackets `%5B%5D`).
 */
final class QueryEncoder
{
    /**
     * @param array<string, mixed> $query
     */
    public static function encode(array $query): string
    {
        $pairs = [];

        foreach ($query as $name => $value) {
            self::append($pairs, $name, $value);
        }

        return implode('&', $pairs);
    }

    /**
     * @param list<string> $pairs
     */
    private static function append(array &$pairs, string $name, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value)) {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode(self::scalar($name, $value));

            return;
        }

        if (array_is_list($value)) {
            $items = array_filter($value, static fn(mixed $item): bool => $item !== null);

            if ($items === []) {
                $pairs[] = rawurlencode($name) . '=';

                return;
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    throw new InvalidArgumentException(sprintf('Query parameter "%s": nested lists have no query-string form.', $name));
                }

                $pairs[] = rawurlencode($name . '[]') . '=' . rawurlencode(self::scalar($name, $item));
            }

            return;
        }

        foreach ($value as $key => $item) {
            self::append($pairs, sprintf('%s[%s]', $name, $key), $item);
        }
    }

    private static function scalar(string $name, mixed $value): string
    {
        if ($value instanceof ApiEnum && $value->isUnrecognized()) {
            throw new InvalidArgumentException(sprintf(
                'Query parameter "%s": %s::Unrecognized stands for a value this SDK version does not know and cannot be sent.',
                $name,
                $value::class,
            ));
        }

        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => self::float($name, $value),
            $value instanceof DateTimeInterface => Dates::format($value),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof Stringable => (string) $value,
            default => throw new InvalidArgumentException(sprintf(
                'Query parameter "%s": a value of type %s has no query-string form.',
                $name,
                get_debug_type($value),
            )),
        };
    }

    private static function float(string $name, float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException(sprintf('Query parameter "%s": INF and NAN cannot be sent.', $name));
        }

        return (string) $value;
    }
}
