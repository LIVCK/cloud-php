<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Testing;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * What the {@see FakeHttpClient} throws for {@see MockResponse::networkError()}: a
 * PSR-18 network exception, exactly what a real client raises when no response arrives.
 */
final class FakeNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message, private readonly RequestInterface $request)
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
