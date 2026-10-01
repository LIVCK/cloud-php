<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data\Concerns;

use DateTimeImmutable;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\Dates;
use LIVCK\Cloud\Support\Field;

/**
 * The figures of a server agent, keyed by metric name: `{"sys.cpu.total_pct": 12.5, …}` for one
 * value per key (null where the catalog lists a key nothing reported), `{"used_pct": 71, …}` for
 * a device (only what it reported), `{"sys.cpu.total_pct": {…}}` for a block per key,
 * `[12.5, null, …]` for a series, and the `timestamps` a series is aligned with.
 *
 * The keys stay what the server sends (the metric catalog's keys, or a device's sub-metrics);
 * nothing is renamed or dropped. Values are numbers or null (nothing reported). They come from
 * the metrics store, so a numeric string is accepted as well, like the other metrics rows.
 */
trait ReadsMetricMaps
{
    /**
     * A `{key: number|null}` object.
     *
     * @param array<string, mixed> $data
     * @return array<string, float|null>
     */
    private static function metricMap(array $data, string $key): array
    {
        $values = [];

        foreach (Field::object($data, $key) as $name => $value) {
            $values[(string) $name] = self::metricValue($value, sprintf('%s.%s', $key, $name));
        }

        return $values;
    }

    /**
     * A `{key: number}` object: the latest figures of a device, which only lists what it reported.
     *
     * @param array<string, mixed> $data
     * @return array<string, float>
     */
    private static function numberMap(array $data, string $key): array
    {
        $values = [];

        foreach (Field::object($data, $key) as $name => $value) {
            $field = sprintf('%s.%s', $key, $name);
            $values[(string) $name] = self::metricValue($value, $field) ?? throw new UnexpectedResponseException(sprintf('Field "%s": expected number, got null.', $field));
        }

        return $values;
    }

    /**
     * A `{key: {…}}` object, each member an object of its own.
     *
     * @param array<string, mixed> $data
     * @return array<string, array<string, mixed>>
     */
    private static function metricObjects(array $data, string $key): array
    {
        $objects = [];

        foreach (Field::object($data, $key) as $name => $value) {
            if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new UnexpectedResponseException(sprintf('Field "%s.%s": expected object, got %s.', $key, $name, get_debug_type($value)));
            }

            /** @var array<string, mixed> $value */
            $objects[(string) $name] = $value;
        }

        return $objects;
    }

    /**
     * A `[number|null, …]` array.
     *
     * @param array<string, mixed> $data
     * @return list<float|null>
     */
    private static function metricList(array $data, string $key): array
    {
        $values = [];

        foreach (Field::list($data, $key) as $index => $value) {
            $values[] = self::metricValue($value, sprintf('%s[%d]', $key, $index));
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<DateTimeImmutable>
     */
    private static function instantList(array $data, string $key): array
    {
        $instants = [];

        foreach (Field::stringList($data, $key) as $index => $value) {
            $instants[] = Dates::tryParse($value) ?? throw new UnexpectedResponseException(sprintf('Field "%s[%d]": expected ISO 8601 instant, got string.', $key, $index));
        }

        return $instants;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function nullableMetric(array $data, string $key): ?float
    {
        return self::metricValue($data[$key] ?? null, $key);
    }

    private static function metricValue(mixed $value, string $field): ?float
    {
        return match (true) {
            $value === null => null,
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => throw new UnexpectedResponseException(sprintf('Field "%s": expected number or null, got %s.', $field, get_debug_type($value))),
        };
    }
}
