<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use JsonSerializable;
use stdClass;

/**
 * A JSON object in a request body, even when it is empty.
 *
 * PHP has one array type for both JSON shapes, and an empty array encodes as `[]`. The
 * API rejects a list where it expects a map (a service's `config.headers`, for
 * instance), so "no headers" has to go out as `{}`:
 *
 *     'headers' => JsonObject::of($headers)      // {} when $headers is []
 *
 * A `stdClass` in a payload is encoded the same way; this wrapper only spares the cast.
 */
final readonly class JsonObject implements JsonSerializable
{
    /**
     * @param array<string, mixed> $map
     */
    private function __construct(
        private array $map,
    ) {}

    /**
     * @param array<string, mixed> $map
     */
    public static function of(array $map): self
    {
        return new self($map);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->map;
    }

    public function jsonSerialize(): stdClass
    {
        return (object) $this->map;
    }
}
