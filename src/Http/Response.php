<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Http;

use DateTimeImmutable;
use DateTimeZone;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\Json;
use Psr\Http\Message\ResponseInterface;

/**
 * A response of the LIVCK Cloud API, detached from the PSR-7 message that carried it.
 *
 * Self-contained and safe to keep: the body has been read completely, header names are
 * lower-cased, and the only request details kept are method and URI, never the
 * credentials.
 */
final class Response
{
    /** @var array<string, list<string>> */
    private readonly array $headers;

    /** @var array<string, mixed>|null */
    private ?array $decoded = null;

    /**
     * @param array<array-key, string|array<string>> $headers
     */
    public function __construct(
        private readonly int $status,
        array $headers,
        private readonly string $body,
        private readonly string $requestMethod,
        private readonly string $requestUri,
        private readonly string $reasonPhrase = '',
    ) {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $normalized[strtolower((string) $name)] = is_array($values) ? array_values($values) : [$values];
        }

        $this->headers = $normalized;
    }

    public static function fromPsr(ResponseInterface $response, string $requestMethod, string $requestUri): self
    {
        return new self(
            $response->getStatusCode(),
            $response->getHeaders(),
            (string) $response->getBody(),
            $requestMethod,
            $requestUri,
            $response->getReasonPhrase(),
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function reasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * All headers, names lower-cased, each with its list of values.
     *
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /** The first value of a header (case-insensitive), or null. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function contentType(): ?string
    {
        return $this->header('Content-Type');
    }

    /**
     * The body decoded as a JSON object. Decoded once, then cached.
     *
     * @return array<string, mixed>
     *
     * @throws UnexpectedResponseException when the body is not a JSON object
     */
    public function json(): array
    {
        if ($this->decoded === null) {
            try {
                $this->decoded = Json::decode($this->body);
            } catch (UnexpectedResponseException $e) {
                throw new UnexpectedResponseException(
                    sprintf('%s (HTTP %d, %s %s)', $e->getMessage(), $this->status, $this->requestMethod, $this->requestUri),
                    $this,
                );
            }
        }

        return $this->decoded;
    }

    /**
     * The per-token budget as reported in `X-RateLimit-*`; null when the response
     * carried no such headers (an edge error page, for instance).
     */
    public function rateLimit(): ?RateLimit
    {
        return RateLimit::fromHeaders(
            $this->header('X-RateLimit-Limit'),
            $this->header('X-RateLimit-Remaining'),
            $this->header('X-RateLimit-Reset'),
            $this->retryAfter(),
        );
    }

    /**
     * `Retry-After` in seconds, whether it was sent as delta-seconds or as an HTTP-date
     * (measured against $now, which defaults to the current time). Null when absent.
     */
    public function retryAfter(?DateTimeImmutable $now = null): ?int
    {
        $value = $this->header('Retry-After');

        if ($value === null) {
            return null;
        }

        return RetryAfter::seconds($value, $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * Whether this is the stored response of an earlier request with the same
     * Idempotency-Key (`Idempotent-Replayed: true`) rather than a fresh execution.
     */
    public function wasReplayed(): bool
    {
        return strtolower($this->header('Idempotent-Replayed') ?? '') === 'true';
    }

    public function requestMethod(): string
    {
        return $this->requestMethod;
    }

    /** The full request URI (base URI, path and query). Contains no credentials. */
    public function requestUri(): string
    {
        return $this->requestUri;
    }
}
