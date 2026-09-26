<?php

declare(strict_types=1);

namespace LIVCK\Cloud;

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Everything about a client that is not the token. Validated on construction, immutable;
 * every `with…()` returns a copy.
 *
 * `timeout` and `connectTimeout` apply only when the SDK builds the HTTP client itself
 * (Guzzle, when it is installed). A client passed in keeps its own settings.
 */
final readonly class ClientOptions
{
    public const string DEFAULT_BASE_URI = 'https://api.livck.cloud/v1';

    public const int MAX_RETRIES_CEILING = 10;

    /** Absolute base of every request, without a trailing slash. */
    public string $baseUri;

    /**
     * @param string $baseUri https, or http on a loopback host (`localhost`, `*.localhost`, `127.0.0.0/8`, `::1`)
     * @param string|null $locale sent as `Accept-Language`; picks the language translatable fields are resolved to
     * @param float $timeout seconds for the whole request
     * @param float $connectTimeout seconds to establish the connection
     * @param int $maxRetries additional attempts after the first (0 disables retries)
     * @param float $backoffBase seconds; the first retry waits up to this, doubling per retry
     * @param float $backoffCap seconds; the longest backoff wait
     * @param int $maxRetryAfter seconds; a `Retry-After` beyond this fails fast instead of waiting
     * @param bool $idempotency generate an `Idempotency-Key` for every POST
     * @param string|null $userAgentSuffix appended to the User-Agent, e.g. `my-panel/2.3`
     */
    public function __construct(
        string $baseUri = self::DEFAULT_BASE_URI,
        public ?string $locale = null,
        public float $timeout = 30.0,
        public float $connectTimeout = 10.0,
        public int $maxRetries = 2,
        public float $backoffBase = 0.5,
        public float $backoffCap = 8.0,
        public int $maxRetryAfter = 60,
        public bool $idempotency = true,
        public ?string $userAgentSuffix = null,
        public LoggerInterface $logger = new NullLogger(),
    ) {
        $this->baseUri = $this->normalizeBaseUri($baseUri);

        if ($locale !== null) {
            $this->assertHeaderValue($locale, 'locale');
        }

        if ($userAgentSuffix !== null) {
            $this->assertHeaderValue($userAgentSuffix, 'userAgentSuffix');
        }

        if ($timeout <= 0.0 || $connectTimeout <= 0.0) {
            throw new InvalidArgumentException('timeout and connectTimeout must be positive.');
        }

        if ($maxRetries < 0 || $maxRetries > self::MAX_RETRIES_CEILING) {
            throw new InvalidArgumentException(sprintf('maxRetries must be between 0 and %d.', self::MAX_RETRIES_CEILING));
        }

        if ($backoffBase <= 0.0) {
            throw new InvalidArgumentException('backoffBase must be positive.');
        }

        if ($backoffCap < $backoffBase) {
            throw new InvalidArgumentException('backoffCap must be at least backoffBase.');
        }

        if ($maxRetryAfter < 0) {
            throw new InvalidArgumentException('maxRetryAfter must not be negative.');
        }
    }

    public function withBaseUri(string $baseUri): self
    {
        return new self($baseUri, $this->locale, $this->timeout, $this->connectTimeout, $this->maxRetries, $this->backoffBase, $this->backoffCap, $this->maxRetryAfter, $this->idempotency, $this->userAgentSuffix, $this->logger);
    }

    public function withLocale(?string $locale): self
    {
        return new self($this->baseUri, $locale, $this->timeout, $this->connectTimeout, $this->maxRetries, $this->backoffBase, $this->backoffCap, $this->maxRetryAfter, $this->idempotency, $this->userAgentSuffix, $this->logger);
    }

    public function withTimeout(float $timeout, ?float $connectTimeout = null): self
    {
        return new self($this->baseUri, $this->locale, $timeout, $connectTimeout ?? $this->connectTimeout, $this->maxRetries, $this->backoffBase, $this->backoffCap, $this->maxRetryAfter, $this->idempotency, $this->userAgentSuffix, $this->logger);
    }

    public function withMaxRetries(int $maxRetries): self
    {
        return new self($this->baseUri, $this->locale, $this->timeout, $this->connectTimeout, $maxRetries, $this->backoffBase, $this->backoffCap, $this->maxRetryAfter, $this->idempotency, $this->userAgentSuffix, $this->logger);
    }

    public function withBackoff(float $base, float $cap): self
    {
        return new self($this->baseUri, $this->locale, $this->timeout, $this->connectTimeout, $this->maxRetries, $base, $cap, $this->maxRetryAfter, $this->idempotency, $this->userAgentSuffix, $this->logger);
    }

    public function withMaxRetryAfter(int $seconds): self
    {
        return new self($this->baseUri, $this->locale, $this->timeout, $this->connectTimeout, $this->maxRetries, $this->backoffBase, $this->backoffCap, $seconds, $this->idempotency, $this->userAgentSuffix, $this->logger);
    }

    public function withIdempotency(bool $enabled): self
    {
        return new self($this->baseUri, $this->locale, $this->timeout, $this->connectTimeout, $this->maxRetries, $this->backoffBase, $this->backoffCap, $this->maxRetryAfter, $enabled, $this->userAgentSuffix, $this->logger);
    }

    public function withUserAgentSuffix(?string $suffix): self
    {
        return new self($this->baseUri, $this->locale, $this->timeout, $this->connectTimeout, $this->maxRetries, $this->backoffBase, $this->backoffCap, $this->maxRetryAfter, $this->idempotency, $suffix, $this->logger);
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self($this->baseUri, $this->locale, $this->timeout, $this->connectTimeout, $this->maxRetries, $this->backoffBase, $this->backoffCap, $this->maxRetryAfter, $this->idempotency, $this->userAgentSuffix, $logger);
    }

    private function normalizeBaseUri(string $baseUri): string
    {
        $baseUri = trim($baseUri);
        $parts = parse_url($baseUri);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(sprintf('The base URI must be an absolute URL such as %s.', self::DEFAULT_BASE_URI));
        }

        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('The base URI must not carry a query string, a fragment or credentials.');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ($scheme !== 'http' || !$this->isLoopback($parts['host']))) {
            throw new InvalidArgumentException(
                'The base URI must use https. Plain http is accepted for loopback hosts only '
                . '(localhost, *.localhost, 127.0.0.0/8, ::1), so a token never travels unencrypted by accident.',
            );
        }

        return rtrim($baseUri, '/');
    }

    private function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return inet_pton($host) === inet_pton('::1');
        }

        return false;
    }

    private function assertHeaderValue(string $value, string $option): void
    {
        if ($value === '' || preg_match('/\A[\x20-\x7E]+\z/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty printable ASCII string.', $option));
        }
    }
}
