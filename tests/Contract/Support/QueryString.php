<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

/**
 * A raw query string split into its parameters, without PHP's `parse_str()` rewriting
 * names: `probe[]` stays recognisable as the repeatable form.
 */
final class QueryString
{
    /**
     * @return array<string, QueryValue> keyed by base name, in order of first appearance
     */
    public static function parse(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $parsed = [];

        foreach (explode('&', $query) as $pair) {
            [$rawName, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = rawurldecode($rawName);
            $value = rawurldecode($rawValue);
            $isList = str_ends_with($name, '[]');
            $baseName = $isList ? substr($name, 0, -2) : $name;
            $existing = $parsed[$baseName] ?? null;

            $parsed[$baseName] = $existing instanceof QueryValue
                ? new QueryValue($existing->name, $baseName, true, [...$existing->values, $value])
                : new QueryValue($name, $baseName, $isList, [$value]);
        }

        return $parsed;
    }
}
