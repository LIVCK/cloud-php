<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use BackedEnum;
use DateTimeInterface;
use JsonException;
use JsonSerializable;
use LIVCK\Cloud\Enums\ApiEnum;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use stdClass;

/**
 * The one JSON codec of the SDK.
 *
 * Decoding is strict: anything that is not a JSON object (or an empty array) is refused,
 * so a resource never quietly works on a scalar or a list where an object was promised.
 * Encoding gives every value its wire form: instants become ISO 8601 UTC, enums their
 * backing value, a {@see JsonObject} or `stdClass` an object even when empty (`{}`,
 * never `[]`), {@see KeepSecret} and {@see Translatable} serialise themselves.
 */
final class Json
{
    private const ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private const int MAX_DEPTH = 512;

    /**
     * @return array<string, mixed>
     *
     * @throws UnexpectedResponseException when the text is not a JSON object
     */
    public static function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedResponseException('The response body is not valid JSON: ' . $e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new UnexpectedResponseException(sprintf('Expected a JSON object, got %s.', get_debug_type($decoded)));
        }

        if ($decoded !== [] && array_is_list($decoded)) {
            throw new UnexpectedResponseException('Expected a JSON object, got a JSON array.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Like {@see decode()}, but null instead of an exception. For places that must
     * survive a non-JSON body (an error page from the edge).
     *
     * @return array<string, mixed>|null
     */
    public static function tryDecode(string $json): ?array
    {
        try {
            return self::decode($json);
        } catch (UnexpectedResponseException) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @throws InvalidArgumentException when a value has no JSON representation
     */
    public static function encode(array $value): string
    {
        try {
            return json_encode(self::normalize($value), self::ENCODE_FLAGS, self::MAX_DEPTH);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('The request body cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Give every nested value its wire form; leave what json_encode handles natively.
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        if ($value instanceof stdClass) {
            return (object) array_map(self::normalize(...), get_object_vars($value));
        }

        if ($value instanceof DateTimeInterface) {
            return Dates::format($value);
        }

        if ($value instanceof ApiEnum && $value->isUnrecognized()) {
            throw new InvalidArgumentException(sprintf(
                '%s::Unrecognized stands for a value this SDK version does not know and cannot be sent to the API.',
                $value::class,
            ));
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof JsonSerializable) {
            return self::normalize($value->jsonSerialize());
        }

        return $value;
    }
}
