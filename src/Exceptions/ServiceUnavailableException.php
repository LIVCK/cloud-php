<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * 503: temporarily not possible. On the v1 API that is a check history whose store
 * cannot answer (`Retry-After: 30`), a write while monitoring is frozen system-wide, or
 * the platform's maintenance mode.
 */
class ServiceUnavailableException extends ServerException
{
    /** Seconds to wait before trying again, when the server said so. */
    public function retryAfter(): ?int
    {
        return $this->response()->retryAfter();
    }
}
