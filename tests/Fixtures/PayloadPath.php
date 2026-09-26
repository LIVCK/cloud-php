<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Fixtures;

use RuntimeException;

/**
 * Typed access into a request body's nested blocks, for assertions on one part of it.
 */
final class PayloadPath
{
    /**
     * The `settings` block.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function settings(array $payload): array
    {
        return self::block($payload, 'settings');
    }

    /**
     * The `settings.config` block.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function config(array $payload): array
    {
        return self::block(self::settings($payload), 'config');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function block(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new RuntimeException(sprintf('Expected the payload to carry a "%s" block, got %s.', $key, get_debug_type($value)));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
