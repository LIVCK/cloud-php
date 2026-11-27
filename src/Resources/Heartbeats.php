<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use LIVCK\Cloud\Builders\HeartbeatDeclaration;
use LIVCK\Cloud\Data\HeartbeatDeclarationResult;
use LIVCK\Cloud\Exceptions\ConflictException;
use LIVCK\Cloud\Exceptions\HeartbeatDeclarationConflictException;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Transport;
use LIVCK\Cloud\Support\Envelope;

/**
 * `/v1/heartbeats`.
 */
final readonly class Heartbeats implements HeartbeatsInterface
{
    public function __construct(
        private Transport $transport,
    ) {}

    public function declare(HeartbeatDeclaration $declaration, ?string $idempotencyKey = null): HeartbeatDeclarationResult
    {
        try {
            $response = $this->transport->send(Request::post('heartbeats/declare', $declaration->toArray(), $idempotencyKey));
        } catch (ConflictException $e) {
            // The 409 of a collision with another declaration carries no Retry-After, so the
            // transport hands it on. It is the only state conflict this route has; an
            // Idempotency-Key still in progress stays what it is.
            if ($e->isIdempotencyKeyInProgress()) {
                throw $e;
            }

            throw new HeartbeatDeclarationConflictException($e->response());
        }

        return Envelope::item($response, HeartbeatDeclarationResult::fromArray(...));
    }
}
