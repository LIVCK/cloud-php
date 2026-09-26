<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

/**
 * The part names of a `multipart/form-data` body, read from its Content-Disposition lines.
 */
final class Multipart
{
    /**
     * @return list<string>
     */
    public static function partNames(string $body): array
    {
        preg_match_all('/Content-Disposition:\s*form-data;\s*name="([^"]*)"/i', $body, $matches);

        return array_values($matches[1]);
    }
}
