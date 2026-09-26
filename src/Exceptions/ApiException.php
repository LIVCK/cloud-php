<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

use LIVCK\Cloud\Http\Response;
use LIVCK\Cloud\Support\Json;
use RuntimeException;

/**
 * The API answered with an error status.
 *
 * `getMessage()` is the server's message followed by status, method and URI, so a bare
 * log line already says what failed where; {@see errorMessage()} is the server's text
 * alone. `getCode()` is the HTTP status. The token is never part of any of it.
 *
 * Every error body of the v1 API is a `{message, ...}` envelope; validation failures add
 * `errors`, plan gates add `upsell`, a few conflicts add `requires_confirmation`. The
 * subclasses expose those keys; {@see body()} keeps the whole decoded envelope.
 */
class ApiException extends RuntimeException implements LivckCloudException
{
    /** @var array<string, mixed> */
    private readonly array $decoded;

    private readonly string $errorMessage;

    final public function __construct(private readonly Response $response, ?string $message = null)
    {
        $this->decoded = Json::tryDecode($response->body()) ?? [];
        $this->errorMessage = $message ?? $this->messageOf($this->decoded, $response);

        parent::__construct(
            sprintf('%s (HTTP %d, %s %s)', $this->errorMessage, $response->status(), $response->requestMethod(), $response->requestUri()),
            $response->status(),
        );
    }

    /**
     * The exception matching a response.
     *
     * The body decides first: a plan gate announces itself through `upsell.reason`
     * whatever status the endpoint chose (402 from the limit middleware, 403 from a
     * service or custom-domain limit, 403 for a feature). Only then does the status.
     */
    public static function fromResponse(Response $response): self
    {
        $status = $response->status();

        return match (true) {
            self::upsellReasonOf($response) === 'limit' => new PlanLimitException($response),
            self::upsellReasonOf($response) === 'feature' => new FeatureNotAvailableException($response),
            $status === 401 => new AuthenticationException($response),
            $status === 402 => new PlanLimitException($response),
            $status === 403 => new PermissionDeniedException($response),
            $status === 404 => new NotFoundException($response),
            $status === 409 => new ConflictException($response),
            $status === 422 => new ValidationException($response),
            $status === 429 => new RateLimitException($response),
            $status === 503 => new ServiceUnavailableException($response),
            $status >= 500 => new ServerException($response),
            default => new self($response),
        };
    }

    public function status(): int
    {
        return $this->response->status();
    }

    public function response(): Response
    {
        return $this->response;
    }

    /** The server's `message` (or the HTTP reason phrase when the body carried none). */
    public function errorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * The decoded error envelope; empty when the body was not JSON (an edge error page).
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->decoded;
    }

    public function rawBody(): string
    {
        return $this->response->body();
    }

    /**
     * Response headers, names lower-cased.
     *
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->response->headers();
    }

    public function requestMethod(): string
    {
        return $this->response->requestMethod();
    }

    public function requestUri(): string
    {
        return $this->response->requestUri();
    }

    /**
     * Field errors from the envelope's `errors` object, each field with its list of
     * messages. Empty when the response carried none, which is the case for every status
     * but 422, and for some 422s as well (an Idempotency-Key reused with a different
     * payload, a state that does not allow the action).
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $raw = $this->decoded['errors'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $errors = [];

        foreach ($raw as $field => $messages) {
            $list = is_array($messages) ? $messages : [$messages];
            $strings = array_values(array_filter($list, is_string(...)));

            if ($strings !== []) {
                $errors[(string) $field] = $strings;
            }
        }

        return $errors;
    }

    /**
     * The plan gate's `upsell` block, when the response carried one.
     *
     * @return array{reason: ?string, key: ?string}|null
     */
    protected function upsell(): ?array
    {
        $upsell = $this->decoded['upsell'] ?? null;

        if (! is_array($upsell)) {
            return null;
        }

        return [
            'reason' => is_string($upsell['reason'] ?? null) ? $upsell['reason'] : null,
            'key' => is_string($upsell['key'] ?? null) ? $upsell['key'] : null,
        ];
    }

    protected function bodyInt(string $key): ?int
    {
        $value = $this->decoded[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function messageOf(array $decoded, Response $response): string
    {
        $message = $decoded['message'] ?? null;

        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        $reason = $response->reasonPhrase();

        return $reason !== '' ? $reason : sprintf('HTTP %d', $response->status());
    }

    private static function upsellReasonOf(Response $response): ?string
    {
        $upsell = Json::tryDecode($response->body())['upsell'] ?? null;

        if (! is_array($upsell) || ! is_string($upsell['reason'] ?? null)) {
            return null;
        }

        return $upsell['reason'];
    }
}
