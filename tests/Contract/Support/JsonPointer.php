<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

/**
 * RFC 6901 pointers into the API document (`/paths/~1tags~1{tag}/get`).
 */
final class JsonPointer
{
    public static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    public static function of(string ...$tokens): string
    {
        return '/' . implode('/', array_map(self::escape(...), $tokens));
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $pointer): array
    {
        $pointer = ltrim($pointer, '/');

        if ($pointer === '') {
            return [];
        }

        return array_map(
            static fn(string $token): string => str_replace(['~1', '~0'], ['/', '~'], $token),
            explode('/', $pointer),
        );
    }

    /**
     * The pointer as a URI fragment (RFC 6901 section 6): everything outside the unreserved
     * set percent-encoded, the `/` separators kept.
     */
    public static function fragment(string $pointer): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $pointer)));
    }
}
