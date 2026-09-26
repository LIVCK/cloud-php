<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

use RuntimeException;

/**
 * No HTTP response was obtained: DNS failure, refused connection, TLS error, timeout.
 *
 * Whether the request reached the server is unknown. The SDK has already retried where
 * that is safe (see the retry policy), so by the time this surfaces the attempts are spent.
 *
 * The underlying PSR-18 exception is deliberately NOT chained as `previous`: it references
 * the outgoing PSR-7 request, Authorization header included, and would carry the token
 * into every log line that dumps the exception. Its class name and (redacted) message are
 * kept instead.
 */
final class TransportException extends RuntimeException implements LivckCloudException
{
    public function __construct(
        string $message,
        private readonly string $requestMethod,
        private readonly string $requestUri,
        private readonly string $underlyingClass,
        private readonly int $attempts,
    ) {
        parent::__construct(sprintf('%s (%s %s, %d attempt%s)', $message, $requestMethod, $requestUri, $attempts, $attempts === 1 ? '' : 's'));
    }

    public function requestMethod(): string
    {
        return $this->requestMethod;
    }

    public function requestUri(): string
    {
        return $this->requestUri;
    }

    /** Class name of the PSR-18 exception the HTTP client threw. */
    public function underlyingClass(): string
    {
        return $this->underlyingClass;
    }

    /** How many times the request was sent before giving up. */
    public function attempts(): int
    {
        return $this->attempts;
    }
}
