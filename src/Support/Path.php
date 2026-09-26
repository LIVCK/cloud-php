<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Request paths from segments, each percent-encoded (RFC 3986), so an identifier can
 * never change the route it is embedded in.
 *
 *     Path::join('tags', $id)                       // tags/V1StGXR8_Z5jdHi6B-myT
 *     Path::join('statuspages', $page, 'assets', 'logo')
 */
final class Path
{
    public static function join(string ...$segments): string
    {
        if ($segments === []) {
            throw new InvalidArgumentException('A path needs at least one segment.');
        }

        $encoded = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException('A path segment must not be empty.');
            }

            $encoded[] = rawurlencode($segment);
        }

        return implode('/', $encoded);
    }
}
