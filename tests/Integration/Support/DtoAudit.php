<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Integration\Support;

use Closure;
use DateTimeInterface;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\ConditionRule;
use LIVCK\Cloud\Enums\ApiEnum;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;

/**
 * Walks every DTO a live call returned and holds it against two questions.
 *
 * The first fails the scenario: does any enum property carry `Unrecognized`? That is live
 * data this SDK version does not understand (a status, a kind, a check type the server added),
 * and a release must know about it.
 *
 * The second is reported, never failed: which keys of a DTO's raw payload does no property
 * map? Those are fields the server sends and the SDK ignores; the list per DTO class goes
 * into the run's report so the next release can decide about them. A property covers a raw
 * key when the two agree after case and underscores are dropped (`checkTypeLabel` covers
 * `check_type_label`, `uptime30d` covers `uptime_30d`); the few DTOs that rename a field
 * are listed in {@see ALIASES}.
 */
final class DtoAudit
{
    /** Raw keys a DTO maps under another property name. */
    private const array ALIASES = [
        ConditionRule::class => ['status'], // read as `outcome`
    ];

    /** DTOs whose raw payload is keyed by data (the catalog: one entry per check type), not by field. */
    private const array RAW_IS_DATA = [
        CheckTypeCatalog::class,
    ];

    /** @var array<string, array<string, int>> class => raw key => how often it was seen */
    private static array $unmapped = [];

    /** @var array<string, int> class => DTOs inspected */
    private static array $inspected = [];

    /**
     * Inspects a DTO, a list of DTOs or a page of them and hands it back, so it can wrap a
     * call: `$service = DtoAudit::inspect($client->services()->get($id), 'services.get')`.
     *
     * @template T
     *
     * @param T $value
     * @return T
     *
     * @throws RuntimeException when live data carries an enum value the SDK does not know
     */
    public static function inspect(mixed $value, string $context): mixed
    {
        $unrecognized = [];
        $seen = [];

        self::walk($value, $context, $unrecognized, $seen);

        if ($unrecognized !== []) {
            throw new RuntimeException(sprintf(
                "Live data carries enum values this SDK version does not know (the raw value is kept on the DTO):\n - %s",
                implode("\n - ", $unrecognized),
            ));
        }

        return $value;
    }

    /** How many DTOs with a raw payload were inspected so far. */
    public static function inspected(): int
    {
        return (int) array_sum(self::$inspected);
    }

    /**
     * The raw keys no property maps, per DTO class.
     *
     * @return array<string, list<string>>
     */
    public static function unmapped(): array
    {
        $result = [];
        ksort(self::$unmapped);

        foreach (self::$unmapped as $class => $keys) {
            ksort($keys);
            $result[$class] = array_keys($keys);
        }

        return $result;
    }

    /** One line for a scenario's log. */
    public static function summary(): string
    {
        $classes = count(self::$inspected);
        $unmapped = array_sum(array_map(count(...), self::unmapped()));

        return sprintf('DTO audit: %d objects of %d classes inspected, no unrecognized enum values, %d unmapped raw key%s so far', self::inspected(), $classes, $unmapped, $unmapped === 1 ? '' : 's');
    }

    /** The full report for the end of the run. */
    public static function report(): string
    {
        $lines = ['DTO audit: unmapped raw keys per DTO class (fields the server sends that no property reads)'];
        $inspected = self::$inspected;
        ksort($inspected);

        foreach ($inspected as $class => $count) {
            $keys = self::unmapped()[$class] ?? [];
            $lines[] = sprintf(
                '  %-52s %5d inspected  %s',
                substr($class, strlen('LIVCK\\Cloud\\')),
                $count,
                $keys === [] ? 'every raw key is mapped' : 'unmapped: ' . implode(', ', $keys),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $unrecognized
     * @param array<int, true> $seen
     */
    private static function walk(mixed $value, string $path, array &$unrecognized, array &$seen): void
    {
        if ($value instanceof ApiEnum) {
            if ($value->isUnrecognized()) {
                $unrecognized[] = sprintf('%s (%s)', $path, $value::class);
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::walk($item, sprintf('%s[%s]', $path, $key), $unrecognized, $seen);
            }

            return;
        }

        if (! is_object($value) || $value instanceof DateTimeInterface || $value instanceof Closure) {
            return;
        }

        $id = spl_object_id($value);

        if (isset($seen[$id]) || ! str_starts_with($value::class, 'LIVCK\\Cloud\\')) {
            return;
        }

        $seen[$id] = true;
        $properties = [];

        foreach ((new ReflectionObject($value))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $properties[$property->getName()] = $property->getValue($value);
        }

        $raw = $properties['raw'] ?? null;

        if (is_array($raw)) {
            self::audit($value::class, $properties, $raw);
        }

        foreach ($properties as $name => $item) {
            if ($name !== 'raw') {
                self::walk($item, $path . '.' . $name, $unrecognized, $seen);
            }
        }
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<array-key, mixed> $raw
     */
    private static function audit(string $class, array $properties, array $raw): void
    {
        self::$inspected[$class] = (self::$inspected[$class] ?? 0) + 1;

        if (in_array($class, self::RAW_IS_DATA, true)) {
            return;
        }

        $covered = [];

        foreach (array_keys($properties) as $name) {
            $covered[self::normalize($name)] = true;
        }

        foreach (self::ALIASES[$class] ?? [] as $alias) {
            $covered[self::normalize($alias)] = true;
        }

        foreach (array_keys($raw) as $key) {
            if (! isset($covered[self::normalize((string) $key)])) {
                self::$unmapped[$class][(string) $key] = (self::$unmapped[$class][(string) $key] ?? 0) + 1;
            }
        }
    }

    private static function normalize(string $name): string
    {
        return strtolower(str_replace('_', '', $name));
    }
}
