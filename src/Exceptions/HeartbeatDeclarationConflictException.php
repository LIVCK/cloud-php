<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * 409 from `POST /v1/heartbeats/declare`: another declaration of the same heartbeats ran at the
 * same moment (two instances of an application deploying together, say), and the server could
 * not settle this one after retrying it itself. Nothing is wrong with the declaration: send it
 * again, after a short pause. {@see isRetryable()} is true.
 *
 * Repeating it is safe. A declaration only creates what is missing and aligns what changed, and
 * the server keeps no answer for an Idempotency-Key that ended in an error, so the same key may
 * be used again.
 */
final class HeartbeatDeclarationConflictException extends ConflictException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
