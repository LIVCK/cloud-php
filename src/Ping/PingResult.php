<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Ping;

/**
 * How a heartbeat ping went. A ping never throws; this is where a problem shows.
 *
 * - `sent`: an HTTP response came back. False when nothing could be sent or no answer
 *   arrived (no HTTP client, an unusable URL, DNS, connection, TLS, timeout).
 * - `noResponse`: the ping went out, but no answer came back: the HTTP client reported a
 *   network error (DNS, a refused or lost connection, a timeout). The URL may be fine; the
 *   way to LIVCK was not. False for an unusable URL, a missing HTTP client and every HTTP
 *   status, error statuses included.
 * - `status`: the HTTP status, or null without a response.
 * - `unknownUrl`: LIVCK does not know the URL (404): a typo, a regenerated URL or a deleted
 *   heartbeat. Retrying will not help; the URL needs replacing.
 * - `error`: what went wrong, null on success. Never contains the ping URL.
 * - `droppedMetrics`: keys of figures that were left out of the body (see {@see PingDetails}).
 */
final readonly class PingResult
{
    /**
     * @param list<string> $droppedMetrics
     */
    public function __construct(
        public bool $sent,
        public ?int $status,
        public bool $unknownUrl,
        public ?string $error,
        public array $droppedMetrics = [],
        public bool $noResponse = false,
    ) {}

    /**
     * @param list<string> $droppedMetrics
     */
    public static function fromStatus(int $status, array $droppedMetrics = []): self
    {
        return new self(true, $status, $status === 404, self::describe($status), $droppedMetrics);
    }

    /**
     * Nothing was sent: the URL is unusable or no HTTP client is available.
     *
     * @param list<string> $droppedMetrics
     */
    public static function notSent(string $error, array $droppedMetrics = []): self
    {
        return new self(false, null, false, $error, $droppedMetrics);
    }

    /**
     * The request went out, but no answer arrived (DNS, connection, timeout).
     *
     * @param list<string> $droppedMetrics
     */
    public static function noResponse(string $error, array $droppedMetrics = []): self
    {
        return new self(false, null, false, $error, $droppedMetrics, true);
    }

    /** LIVCK accepted the ping (2xx). */
    public function ok(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    private static function describe(int $status): ?string
    {
        return match (true) {
            $status >= 200 && $status < 300 => null,
            $status === 404 => 'LIVCK does not know this ping URL (HTTP 404). It may have been regenerated or the heartbeat deleted.',
            $status === 429 => 'Too many pings for this URL (HTTP 429); this ping was not recorded.',
            $status >= 300 && $status < 400 => sprintf('Unexpected redirect (HTTP %d); redirects are not followed.', $status),
            $status >= 500 => sprintf('LIVCK could not record the ping (HTTP %d).', $status),
            default => sprintf('LIVCK refused the ping (HTTP %d).', $status),
        };
    }
}
