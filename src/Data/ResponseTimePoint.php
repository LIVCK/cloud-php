<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\Field;

/**
 * One point of the response-time trend (`GET /v1/services/{id}/response-times`): the
 * latency figures of one bucket (an hour, or five minutes for a young service), in
 * milliseconds.
 */
final readonly class ResponseTimePoint
{
    /**
     * @param DateTimeImmutable $timestamp the start of the bucket, UTC
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public DateTimeImmutable $timestamp,
        public ?float $avgMs,
        public ?float $minMs,
        public ?float $maxMs,
        public ?float $p95Ms,
        public ?float $p99Ms,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::instant($data, 'timestamp'),
            self::number($data, 'avg_ms'),
            self::number($data, 'min_ms'),
            self::number($data, 'max_ms'),
            self::number($data, 'p95_ms'),
            self::number($data, 'p99_ms'),
            $data,
        );
    }

    /**
     * The trend rows come straight from the metrics store, which writes 64-bit integers as
     * quoted strings; a numeric string is accepted here where every other DTO insists on a
     * JSON number.
     *
     * @param array<string, mixed> $data
     */
    private static function number(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        throw new UnexpectedResponseException(sprintf('Field "%s": expected number or null, got %s.', $key, get_debug_type($value)));
    }
}
