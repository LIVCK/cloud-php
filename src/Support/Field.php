<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use DateTimeImmutable;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;

/**
 * Typed reads from a decoded payload, for the hand-written `fromArray()` of every DTO.
 *
 * Each accessor checks the type and fails with the field name, so a payload that drifted
 * from the documented shape is reported as "field `color` expected string, got null" and
 * not as a TypeError three frames deeper. Missing keys count as null.
 *
 * Instants accept every form the API writes (`2026-08-01T12:00:00+00:00`,
 * `2026-08-01T12:00:00.000Z`, `2026-08-01T12:00:00Z`) and come back in UTC. A bare
 * `YYYY-MM-DD` is a calendar day: read it with {@see day()}, or accept it as midnight UTC
 * through {@see instant()} when the field is documented as an instant.
 */
final class Field
{
    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : self::fail($key, 'string', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return $value === null || is_string($value) ? $value : self::fail($key, 'string or null', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : self::fail($key, 'integer', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return $value === null || is_int($value) ? $value : self::fail($key, 'integer or null', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : self::fail($key, 'number', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableFloat(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        return is_int($value) || is_float($value) ? (float) $value : self::fail($key, 'number or null', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : self::fail($key, 'boolean', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableBool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return $value === null || is_bool($value) ? $value : self::fail($key, 'boolean or null', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function instant(array $data, string $key): DateTimeImmutable
    {
        return self::nullableInstant($data, $key) ?? self::fail($key, 'ISO 8601 instant', null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableInstant(array $data, string $key): ?DateTimeImmutable
    {
        $value = self::nullableString($data, $key);

        try {
            return Dates::parse($value);
        } catch (InvalidArgumentException) {
            return self::fail($key, 'ISO 8601 instant', $value);
        }
    }

    /**
     * A calendar day (`YYYY-MM-DD`), kept apart from instants.
     *
     * @param array<string, mixed> $data
     */
    public static function day(array $data, string $key): Day
    {
        return self::nullableDay($data, $key) ?? self::fail($key, 'calendar day (YYYY-MM-DD)', null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableDay(array $data, string $key): ?Day
    {
        $value = self::nullableString($data, $key);

        if ($value === null) {
            return null;
        }

        return Day::tryFromString($value) ?? self::fail($key, 'calendar day (YYYY-MM-DD)', $value);
    }

    /**
     * A nested object.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return self::fail($key, 'object', $value);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A nested object, or null.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function nullableObject(array $data, string $key): ?array
    {
        return ($data[$key] ?? null) === null ? null : self::object($data, $key);
    }

    /**
     * A JSON array; a missing key or null reads as an empty list.
     *
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    public static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return [];
        }

        return is_array($value) && array_is_list($value) ? $value : self::fail($key, 'array', $value);
    }

    /**
     * A JSON array of objects, e.g. the `data` of a list response.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $data, string $key): array
    {
        $items = [];

        foreach (self::list($data, $key) as $index => $item) {
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                return self::fail(sprintf('%s[%d]', $key, $index), 'object', $item);
            }

            /** @var array<string, mixed> $item */
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        $items = [];

        foreach (self::list($data, $key) as $index => $item) {
            $items[] = is_string($item) ? $item : self::fail(sprintf('%s[%d]', $key, $index), 'string', $item);
        }

        return $items;
    }

    private static function fail(string $key, string $expected, mixed $actual): never
    {
        throw new UnexpectedResponseException(sprintf(
            'Field "%s": expected %s, got %s.',
            $key,
            $expected,
            get_debug_type($actual),
        ));
    }
}
