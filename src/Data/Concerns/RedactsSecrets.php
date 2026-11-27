<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data\Concerns;

use SensitiveParameter;

/**
 * Keeps a credential out of a DTO's `$raw`: every string that contains it, at any depth, becomes
 * `[redacted]`. Used where the secret field itself is already taken out, so that a field the
 * server adds later (another install command, a second URL) cannot leak it either.
 */
trait RedactsSecrets
{
    /**
     * @template K of array-key
     *
     * @param array<K, mixed> $data
     * @return array<K, mixed>
     */
    private static function redacted(#[SensitiveParameter] array $data, #[SensitiveParameter] string $secret): array
    {
        foreach ($data as $field => $value) {
            if (is_string($value) && str_contains($value, $secret)) {
                $data[$field] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$field] = self::redacted($value, $secret);
            }
        }

        return $data;
    }
}
