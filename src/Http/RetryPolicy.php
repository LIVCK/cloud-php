<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Http;

use Closure;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * When a request is sent again, and how long to wait first.
 *
 * Retried:
 *  - transport errors (no response at all) for every method, with two exceptions that could
 *    apply a write twice: a POST without an Idempotency-Key, and a PATCH while idempotency
 *    is switched off. With the key the server replays or de-duplicates a POST. A v1 PATCH
 *    sets fields to the values sent, so repeating it is harmless; switching idempotency off
 *    is the caller's way to rule out any automatic repeat of a write;
 *  - 429 for every method;
 *  - 409 only when it is the server's "a request with this Idempotency-Key is still
 *    being processed" (the first attempt is still running; its answer is replayed);
 *  - 502, 503 and 504 for GET, HEAD, PUT, DELETE and OPTIONS. Never for POST or PATCH: a
 *    gateway error after a committed write must not run the write a second time.
 *
 * Never retried: any other 4xx (the request itself is wrong) and 500.
 *
 * Delay: `Retry-After` when the server sends one, up to `maxRetryAfter` (a longer wait
 * fails fast instead of blocking a worker); otherwise exponential backoff with full
 * jitter, `random(0, min(cap, base * 2^(retry-1)))`.
 */
final readonly class RetryPolicy
{
    private const array REPEATABLE_ON_GATEWAY_ERROR = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    /** @var Closure(): float */
    private Closure $random;

    /**
     * @param Closure(): float|null $random uniform in [0, 1); injectable for deterministic tests
     */
    public function __construct(
        public int $maxRetries = 2,
        public float $backoffBase = 0.5,
        public float $backoffCap = 8.0,
        public int $maxRetryAfter = 60,
        public bool $idempotencyEnabled = true,
        ?Closure $random = null,
    ) {
        if ($maxRetries < 0) {
            throw new InvalidArgumentException('maxRetries must not be negative.');
        }

        if ($backoffBase <= 0.0 || $backoffCap < $backoffBase) {
            throw new InvalidArgumentException('backoffBase must be positive and backoffCap at least backoffBase.');
        }

        if ($maxRetryAfter < 0) {
            throw new InvalidArgumentException('maxRetryAfter must not be negative.');
        }

        $this->random = $random ?? static fn(): float => random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
    }

    /**
     * @param Closure(): float|null $random
     */
    public static function fromOptions(ClientOptions $options, ?Closure $random = null): self
    {
        return new self(
            $options->maxRetries,
            $options->backoffBase,
            $options->backoffCap,
            $options->maxRetryAfter,
            $options->idempotency,
            $random,
        );
    }

    public function retriesTransportError(string $method, bool $hasIdempotencyKey): bool
    {
        return match ($method) {
            'POST' => $hasIdempotencyKey,
            'PATCH' => $this->idempotencyEnabled,
            default => true,
        };
    }

    public function retriesStatus(int $status, string $method, bool $idempotencyKeyInProgress): bool
    {
        return match (true) {
            $status === 429 => true,
            $status === 409 => $idempotencyKeyInProgress,
            $status === 502, $status === 503, $status === 504 => in_array($method, self::REPEATABLE_ON_GATEWAY_ERROR, true),
            default => false,
        };
    }

    /**
     * Seconds to wait before retry number $retry (1-based). Null when the server's
     * `Retry-After` exceeds the configured maximum: do not retry, fail fast.
     *
     * A `Retry-After` is whole seconds, and the server rounds the remaining time down: a
     * client that waits exactly the announced seconds can arrive a fraction of a second
     * early and be answered with `Retry-After: 0`, which means "less than a second", not
     * "now". The wait is therefore never shorter than one second, so a truncated header
     * cannot spend every retry within milliseconds.
     */
    public function delay(int $retry, ?int $retryAfter): ?float
    {
        if ($retryAfter !== null) {
            return $retryAfter > $this->maxRetryAfter ? null : max(1.0, (float) $retryAfter);
        }

        return $this->backoff($retry);
    }

    /**
     * Exponential backoff with full jitter for retry number $retry (1-based).
     */
    public function backoff(int $retry): float
    {
        $ceiling = min($this->backoffCap, $this->backoffBase * (2 ** max(0, $retry - 1)));

        return ($this->random)() * $ceiling;
    }
}
