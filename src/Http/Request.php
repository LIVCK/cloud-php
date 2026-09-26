<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Http;

use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * One API call, described independently of any HTTP library: method, path relative to
 * the base URI, query parameters, a JSON body or multipart parts, extra headers and the
 * idempotency key. Immutable; every `with…()` returns a copy.
 *
 * Query values are encoded by {@see QueryEncoder} (lists as `name[]=`, booleans as
 * `1`/`0`, instants as ISO 8601 UTC, enums by value, null omitted). The path is used as
 * given; build it with {@see \LIVCK\Cloud\Support\Path::join()} when it embeds an
 * identifier.
 */
final readonly class Request
{
    public const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * Set by the transport from the client's configuration and the body, never per call.
     */
    private const array RESERVED_HEADERS = ['authorization', 'user-agent', 'content-type', 'content-length', 'host', 'idempotency-key'];

    /** 1 to 255 visible ASCII characters, the server's definition of a well-formed key. */
    private const string IDEMPOTENCY_KEY_PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    private const string HEADER_NAME_PATTERN = '/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/';

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     * @param list<MultipartPart> $multipart
     * @param array<string, string> $headers
     */
    private function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public ?array $json = null,
        public array $multipart = [],
        public array $headers = [],
        public ?string $idempotencyKey = null,
        public bool $autoIdempotencyKey = true,
    ) {}

    public static function make(string $method, string $path): self
    {
        $method = strtoupper(trim($method));

        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a supported HTTP method.', $method));
        }

        return new self($method, self::normalizePath($path));
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function get(string $path, array $query = []): self
    {
        return self::make('GET', $path)->withQuery($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function head(string $path, array $query = []): self
    {
        return self::make('HEAD', $path)->withQuery($query);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param string|null $idempotencyKey the caller's own key instead of the generated one
     *                                    ({@see withIdempotencyKey()}); null keeps the automatic key
     */
    public static function post(string $path, ?array $json = null, ?string $idempotencyKey = null): self
    {
        $request = self::make('POST', $path)->withJson($json);

        return $idempotencyKey === null ? $request : $request->withIdempotencyKey($idempotencyKey);
    }

    /**
     * @param array<string, mixed>|null $json
     */
    public static function put(string $path, ?array $json = null): self
    {
        return self::make('PUT', $path)->withJson($json);
    }

    /**
     * @param array<string, mixed>|null $json
     */
    public static function patch(string $path, ?array $json = null): self
    {
        return self::make('PATCH', $path)->withJson($json);
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function delete(string $path, array $query = []): self
    {
        return self::make('DELETE', $path)->withQuery($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function withQuery(array $query): self
    {
        foreach (array_keys($query) as $name) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException('Query parameter names must be non-empty strings.');
            }
        }

        return new self($this->method, $this->path, $query, $this->json, $this->multipart, $this->headers, $this->idempotencyKey, $this->autoIdempotencyKey);
    }

    /**
     * @param array<string, mixed>|null $json
     */
    public function withJson(?array $json): self
    {
        if ($json !== null && $this->multipart !== []) {
            throw new InvalidArgumentException('A request carries either a JSON body or multipart parts, not both.');
        }

        return new self($this->method, $this->path, $this->query, $json, $this->multipart, $this->headers, $this->idempotencyKey, $this->autoIdempotencyKey);
    }

    /**
     * A `multipart/form-data` body. POST only: the server parses multipart bodies for
     * POST alone, which is why the asset upload is a POST.
     */
    public function withMultipart(MultipartPart ...$parts): self
    {
        if ($parts === []) {
            throw new InvalidArgumentException('A multipart body needs at least one part.');
        }

        if ($this->method !== 'POST') {
            throw new InvalidArgumentException('Multipart bodies are sent with POST only.');
        }

        if ($this->json !== null) {
            throw new InvalidArgumentException('A request carries either a JSON body or multipart parts, not both.');
        }

        return new self($this->method, $this->path, $this->query, null, array_values($parts), $this->headers, $this->idempotencyKey, $this->autoIdempotencyKey);
    }

    /**
     * An extra header. Authorization, User-Agent, Content-Type, Content-Length, Host and
     * Idempotency-Key are managed by the transport and refused here.
     */
    public function withHeader(string $name, string $value): self
    {
        return $this->withHeaders([$name => $value]);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $merged = $this->headers;

        foreach ($headers as $name => $value) {
            if (preg_match(self::HEADER_NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid header name.', $name));
            }

            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'The %s header is managed by the client and cannot be set per request%s.',
                    $name,
                    strtolower($name) === 'idempotency-key' ? ' (use withIdempotencyKey())' : '',
                ));
            }

            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidArgumentException(sprintf('The %s header value must not contain line breaks.', $name));
            }

            // One value per name: a later call replaces an earlier one, whatever the casing.
            foreach (array_keys($merged) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    unset($merged[$existing]);
                }
            }

            $merged[$name] = $value;
        }

        return new self($this->method, $this->path, $this->query, $this->json, $this->multipart, $merged, $this->idempotencyKey, $this->autoIdempotencyKey);
    }

    /**
     * Use this key instead of a generated one, e.g. your own order number, so that a
     * repeat of the same business operation is recognised across process restarts.
     * POST only: the server ignores the header on every other method.
     */
    public function withIdempotencyKey(string $key): self
    {
        if ($this->method !== 'POST') {
            throw new InvalidArgumentException('An Idempotency-Key applies to POST requests only.');
        }

        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('An Idempotency-Key must be 1 to 255 visible ASCII characters (no whitespace).');
        }

        return new self($this->method, $this->path, $this->query, $this->json, $this->multipart, $this->headers, $key, false);
    }

    /**
     * Send this POST without an Idempotency-Key, even when the client generates one by
     * default. A transport error will then not be retried for it.
     */
    public function withoutIdempotencyKey(): self
    {
        return new self($this->method, $this->path, $this->query, $this->json, $this->multipart, $this->headers, null, false);
    }

    public function hasBody(): bool
    {
        return $this->json !== null || $this->multipart !== [];
    }

    public function isMultipart(): bool
    {
        return $this->multipart !== [];
    }

    /** Path and encoded query, without the base URI. */
    public function target(): string
    {
        $query = QueryEncoder::encode($this->query);

        return $this->path . ($query === '' ? '' : '?' . $query);
    }

    private static function normalizePath(string $path): string
    {
        if (str_contains($path, '://') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('The request path is relative to the base URI; pass "tags", not a full URL.');
        }

        $path = ltrim($path, '/');

        if ($path === '') {
            throw new InvalidArgumentException('The request path must not be empty.');
        }

        if (str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('The request path must not contain a query string or fragment; pass query parameters separately.');
        }

        if (preg_match('/[\s\0]/', $path) === 1) {
            throw new InvalidArgumentException('The request path must not contain whitespace or control characters.');
        }

        return $path;
    }
}
