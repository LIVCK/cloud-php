<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * The per-token request budget (`GET /v1/me`). The live counters travel in the
 * `X-RateLimit-*` headers of every response ({@see \LIVCK\Cloud\Http\Response::rateLimit()}).
 */
final readonly class MeRateLimit
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public int $requestsPerMinute,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::int($data, 'requests_per_minute'),
            $data,
        );
    }
}
