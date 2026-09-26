<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Testing;

use LIVCK\Cloud\Support\Json;
use Psr\Http\Message\RequestInterface;

/**
 * A request the {@see FakeHttpClient} received, frozen for assertions.
 */
final readonly class RecordedRequest
{
    /**
     * @param array<string, list<string>> $headers names lower-cased
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $headers,
        public string $body,
    ) {}

    public static function fromPsr(RequestInterface $request): self
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = array_values($values);
        }

        $stream = $request->getBody();
        $body = (string) $stream;

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return new self($request->getMethod(), (string) $request->getUri(), $headers, $body);
    }

    /** The URI path, e.g. `/v1/tags/abc`. */
    public function path(): string
    {
        return (string) parse_url($this->uri, PHP_URL_PATH);
    }

    public function queryString(): string
    {
        return (string) parse_url($this->uri, PHP_URL_QUERY);
    }

    /**
     * The query string parsed the way PHP (and Laravel) reads it: `probe[]=a&probe[]=b`
     * becomes `['probe' => ['a', 'b']]`.
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        parse_str($this->queryString(), $parsed);
        $query = [];

        foreach ($parsed as $name => $value) {
            $query[(string) $name] = $value;
        }

        return $query;
    }

    /** Method and path match (path compared exactly, e.g. `/v1/tags`). */
    public function matches(string $method, string $path): bool
    {
        return strcasecmp($this->method, $method) === 0 && $this->path() === $path;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function idempotencyKey(): ?string
    {
        return $this->header('Idempotency-Key');
    }

    public function isJson(): bool
    {
        return str_starts_with(strtolower($this->header('Content-Type') ?? ''), 'application/json');
    }

    /**
     * The JSON body decoded, or null when there is none (or it is not a JSON object).
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        return $this->body === '' ? null : Json::tryDecode($this->body);
    }
}
