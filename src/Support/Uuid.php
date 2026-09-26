<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

/**
 * Random UUIDs (version 4) for idempotency keys: 122 bits from the CSPRNG, printed in
 * the canonical 36-character form.
 */
final class Uuid
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // RFC 9562 variant

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public static function isV4(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
