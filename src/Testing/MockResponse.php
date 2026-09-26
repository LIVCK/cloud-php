<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Testing;

use JsonSerializable;
use LIVCK\Cloud\Support\Json;

/**
 * A canned answer for the {@see FakeHttpClient}.
 *
 *     MockResponse::json(['data' => ['id' => 'abc', ...]], 201)
 *     MockResponse::error("Your plan's service limit has been reached.", 403, extra: ['upsell' => ['reason' => 'limit', 'key' => 'services']])
 *     MockResponse::page($tags, currentPage: 1, lastPage: 3, perPage: 50)
 *     MockResponse::networkError()
 */
final readonly class MockResponse
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public ?string $networkError = null,
    ) {}

    /**
     * @param array<array-key, mixed>|JsonSerializable $body
     * @param array<string, string> $headers
     */
    public static function json(array|JsonSerializable $body, int $status = 200, array $headers = []): self
    {
        $normalized = $body instanceof JsonSerializable ? $body->jsonSerialize() : $body;

        return new self($status, ['Content-Type' => 'application/json', ...$headers], is_array($normalized) ? Json::encode($normalized) : Json::encode([$normalized]));
    }

    /**
     * The API's error envelope: `{message}` plus `errors` for a 422 and whatever else the
     * endpoint adds (`upsell`, `limit`, `usage`, `requires_confirmation`).
     *
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    public static function error(string $message, int $status, array $errors = [], array $extra = [], array $headers = []): self
    {
        $body = ['message' => $message];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return self::json([...$body, ...$extra], $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function noContent(array $headers = []): self
    {
        return new self(204, $headers, '');
    }

    /**
     * Any body with any status, for responses that are not JSON (an edge error page).
     *
     * @param array<string, string> $headers
     */
    public static function raw(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $headers, $body);
    }

    /**
     * The HTTP client fails without a response (connection refused, timeout).
     */
    public static function networkError(string $message = 'Connection refused'): self
    {
        return new self(0, [], '', $message);
    }

    /**
     * An offset-paginated list in the API's shape.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, string> $headers
     */
    public static function page(array $items, int $currentPage = 1, int $lastPage = 1, int $perPage = 50, ?int $total = null, array $headers = []): self
    {
        $count = count($items);
        $total ??= $currentPage === $lastPage ? ($currentPage - 1) * $perPage + $count : $lastPage * $perPage;
        $from = $count === 0 ? null : ($currentPage - 1) * $perPage + 1;
        $to = $from === null ? null : $from + $count - 1;
        $link = static fn(int $page): string => 'https://api.livck.cloud/v1/list?page=' . $page;

        return self::json([
            'data' => $items,
            'links' => [
                'first' => $link(1),
                'last' => $link($lastPage),
                'prev' => $currentPage > 1 ? $link($currentPage - 1) : null,
                'next' => $currentPage < $lastPage ? $link($currentPage + 1) : null,
            ],
            'meta' => [
                'current_page' => $currentPage,
                'from' => $from,
                'last_page' => $lastPage,
                'links' => [],
                'path' => 'https://api.livck.cloud/v1/list',
                'per_page' => $perPage,
                'to' => $to,
                'total' => $total,
            ],
        ], 200, $headers);
    }

    /**
     * A keyset-paginated list in the API's shape (the check history).
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, string> $headers
     */
    public static function cursorPage(array $items, ?string $nextCursor = null, int $perPage = 50, array $headers = []): self
    {
        return self::json([
            'data' => $items,
            'meta' => ['per_page' => $perPage, 'next_cursor' => $nextCursor],
            'links' => ['next' => $nextCursor === null ? null : 'https://api.livck.cloud/v1/list?cursor=' . $nextCursor],
        ], 200, $headers);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, [...$this->headers, $name => $value], $this->body, $this->networkError);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, [...$this->headers, ...$headers], $this->body, $this->networkError);
    }

    /** Marks the response as the stored answer of an earlier identical request. */
    public function replayed(): self
    {
        return $this->withHeader('Idempotent-Replayed', 'true');
    }

    public function withRetryAfter(int|string $value): self
    {
        return $this->withHeader('Retry-After', (string) $value);
    }

    public function withRateLimit(int $limit, int $remaining, ?int $resetsAt = null): self
    {
        $headers = ['X-RateLimit-Limit' => (string) $limit, 'X-RateLimit-Remaining' => (string) $remaining];

        if ($resetsAt !== null) {
            $headers['X-RateLimit-Reset'] = (string) $resetsAt;
        }

        return $this->withHeaders($headers);
    }

    public function isNetworkError(): bool
    {
        return $this->networkError !== null;
    }
}
