<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

use LIVCK\Cloud\Http\RateLimit;

/**
 * 429: the per-token budget (120 requests per minute on the v1 API) is spent, or a
 * per-resource cooldown is active (a custom-domain re-check moments after the last).
 *
 * The transport already waited for `Retry-After` and retried; this surfaces once the
 * retries are spent, or immediately when `Retry-After` exceeds the configured maximum.
 */
class RateLimitException extends ApiException
{
    /** Seconds to wait before trying again, when the server said so. */
    public function retryAfter(): ?int
    {
        return $this->response()->retryAfter();
    }

    /** The `X-RateLimit-*` figures of the refusing response, when present. */
    public function rateLimit(): ?RateLimit
    {
        return $this->response()->rateLimit();
    }
}
