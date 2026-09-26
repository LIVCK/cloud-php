<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use JsonSerializable;
use Stringable;

/**
 * "Keep the stored secret" for write-only fields.
 *
 * Secret parts of a service config (every HTTP header value, `config.auth.token`,
 * `.password`, `.value`) are never read back: a GET returns this sentinel in their
 * place. Sending the sentinel back in an update leaves the stored value untouched, so a
 * client can round-trip a config without knowing the secrets. Anything else replaces
 * them.
 *
 * Drop an instance into a payload (`'headers' => ['Authorization' => KeepSecret::keep()]`)
 * or compare with {@see isSentinel()} when reading.
 */
final class KeepSecret implements JsonSerializable, Stringable
{
    /** The server's sentinel, verbatim. Printable ASCII so it survives every transport. */
    public const string SENTINEL = '__LIVCK_KEEP_UNCHANGED__';

    private function __construct() {}

    public static function keep(): self
    {
        return new self();
    }

    /**
     * Whether a value read from the API is the sentinel rather than a real secret.
     */
    public static function isSentinel(mixed $value): bool
    {
        return $value === self::SENTINEL || $value instanceof self;
    }

    public function jsonSerialize(): string
    {
        return self::SENTINEL;
    }

    public function __toString(): string
    {
        return self::SENTINEL;
    }
}
