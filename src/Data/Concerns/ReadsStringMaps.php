<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data\Concerns;

use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\Field;

/**
 * A `{name: text}` object read as `array<string, string>`: title translations, header maps.
 * A missing key or null reads as null; an empty map (`{}` or `[]`) as an empty array.
 */
trait ReadsStringMaps
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, string>|null
     */
    private static function nullableStringMap(array $data, string $key): ?array
    {
        $map = Field::nullableObject($data, $key);

        if ($map === null) {
            return null;
        }

        $strings = [];

        foreach ($map as $name => $value) {
            if (! is_string($value)) {
                throw new UnexpectedResponseException(sprintf('Field "%s.%s": expected string, got %s.', $key, $name, get_debug_type($value)));
            }

            $strings[$name] = $value;
        }

        return $strings;
    }
}
