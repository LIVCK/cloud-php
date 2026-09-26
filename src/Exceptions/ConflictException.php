<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * 409: the request is well-formed but the resource's state does not allow it.
 *
 * Three flavours on the v1 API:
 *
 *  - a state transition that is not possible right now (starting a maintenance that is
 *    not scheduled, accepting a cover request somebody else already took);
 *  - a destructive action that needs an explicit `confirmed` flag first
 *    ({@see requiresConfirmation()}: deleting or deactivating an on-call schedule that an
 *    escalation policy targets; the body names the policies);
 *  - a POST whose Idempotency-Key is still being processed by the first request
 *    ({@see isIdempotencyKeyInProgress()}). The transport retries that one on its own
 *    after `Retry-After`; it only surfaces once the retries are spent.
 */
class ConflictException extends ApiException
{
    /**
     * The action is refused until it is repeated with `confirmed = true`.
     */
    public function requiresConfirmation(): bool
    {
        return ($this->body()['requires_confirmation'] ?? null) === true;
    }

    /**
     * The server is still executing the first request that carried this Idempotency-Key.
     *
     * Recognised by `Retry-After`: it is the only 409 of the API that sends the header
     * (state conflicts never do).
     */
    public function isIdempotencyKeyInProgress(): bool
    {
        return $this->response()->hasHeader('Retry-After');
    }
}
