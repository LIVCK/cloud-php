<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

use InvalidArgumentException;
use stdClass;

/**
 * The one form the vendored OpenAPI document is kept in, so that a refresh from the
 * server yields a readable diff: production as the only server, object keys in a stable
 * order at every level, pretty-printed. Arrays keep their order (`required`, `enum` and
 * `anyOf` are positional).
 */
final class SpecNormalizer
{
    public const string PRODUCTION_SERVER = 'https://api.livck.cloud/v1';

    private const int ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public static function normalize(string $json): string
    {
        $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        if (! $document instanceof stdClass || ! isset($document->openapi) || ! isset($document->paths)) {
            throw new InvalidArgumentException('Not an OpenAPI document: expected a JSON object with "openapi" and "paths".');
        }

        $document->servers = [(object) ['url' => self::PRODUCTION_SERVER, 'description' => 'Production']];

        return json_encode(self::sortKeys($document), self::ENCODE_FLAGS) . "\n";
    }

    private static function sortKeys(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = get_object_vars($value);
            ksort($members, SORT_STRING);
            $sorted = new stdClass();

            foreach ($members as $name => $member) {
                $sorted->{$name} = self::sortKeys($member);
            }

            return $sorted;
        }

        if (is_array($value)) {
            return array_map(self::sortKeys(...), $value);
        }

        return $value;
    }
}
