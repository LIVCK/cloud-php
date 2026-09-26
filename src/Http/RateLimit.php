<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Http;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The per-token request budget as the API reports it.
 *
 * Every response carries `X-RateLimit-Limit` (120 per minute on the v1 API) and
 * `X-RateLimit-Remaining`; a 429 adds `Retry-After` and `X-RateLimit-Reset` (a Unix
 * timestamp). `resetsAt` and `retryAfter` are therefore only known once the budget is
 * exhausted.
 */
final readonly class RateLimit
{
    public function __construct(
        public int $limit,
        public int $remaining,
        public ?DateTimeImmutable $resetsAt = null,
        public ?int $retryAfter = null,
    ) {}

    public static function fromHeaders(?string $limit, ?string $remaining, ?string $reset, ?int $retryAfter): ?self
    {
        if ($limit === null || ! ctype_digit($limit)) {
            return null;
        }

        $resetsAt = $reset !== null && ctype_digit($reset)
            ? (new DateTimeImmutable('@' . $reset))->setTimezone(new DateTimeZone('UTC'))
            : null;

        return new self(
            (int) $limit,
            $remaining !== null && ctype_digit($remaining) ? (int) $remaining : 0,
            $resetsAt,
            $retryAfter,
        );
    }

    public function isExhausted(): bool
    {
        return $this->remaining <= 0;
    }
}
