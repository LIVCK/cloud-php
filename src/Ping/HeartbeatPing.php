<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Ping;

use Exception;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\LoopbackHost;
use LIVCK\Cloud\Support\Secret;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;

/**
 * Pings a LIVCK heartbeat from a job: all it needs is the heartbeat's ping URL, no API token.
 *
 *     $ping = new HeartbeatPing((string) getenv('BACKUP_PING_URL'));
 *     $ping->start();
 *     $code = run_backup();
 *     $ping->exitCode($code, PingDetails::make()->withLog($output));
 *
 * A ping never disturbs the job it reports on. No method throws for anything the URL, the
 * network or LIVCK does: an unusable URL, a missing HTTP client, a timeout, a lost
 * connection and every 3xx/4xx/5xx come back as a {@see PingResult}. Each signal is one
 * request: no retry, no wait for `Retry-After`, a {@see DEFAULT_TIMEOUT} of three seconds.
 *
 * The URL is a credential (whoever has it can report for the job), so it is held as a
 * {@see Secret}: it shows in no dump, no log line and no error text, and the ping is never
 * sent with an Authorization header or anything else of the API client.
 *
 * The timeout applies when the ping builds its HTTP client itself (Guzzle, when it is
 * installed). A client passed in keeps its own settings, as does a client found through
 * php-http/discovery when Guzzle is missing: give it a short timeout of its own.
 */
final readonly class HeartbeatPing implements HeartbeatPingInterface
{
    public const float DEFAULT_TIMEOUT = 3.0;

    private ?Secret $url;

    /** Why nothing can be sent, or null when the ping is ready. */
    private ?string $unusable;

    private ?ClientInterface $httpClient;

    private ?RequestFactoryInterface $requestFactory;

    private ?StreamFactoryInterface $streamFactory;

    private string $userAgent;

    /**
     * @param string|Secret $url the heartbeat's ping URL, e.g. `https://ping.livck.cloud/hb_…`;
     *                           https, or http on a loopback host
     * @param float $timeout seconds for the whole request, when the ping builds its own client
     * @param string|null $userAgentSuffix appended to the User-Agent, e.g. `my-panel/2.3`
     *
     * @throws InvalidArgumentException only for a timeout or suffix that cannot work, both
     *                                  written in your code; never for the URL
     */
    public function __construct(
        #[SensitiveParameter]
        string|Secret $url,
        private float $timeout = self::DEFAULT_TIMEOUT,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private LoggerInterface $logger = new NullLogger(),
        ?string $userAgentSuffix = null,
    ) {
        if (! is_finite($timeout) || $timeout <= 0.0) {
            throw new InvalidArgumentException('timeout must be a positive number of seconds.');
        }

        if ($userAgentSuffix !== null && preg_match('/\A[\x20-\x7E]+\z/', $userAgentSuffix) !== 1) {
            throw new InvalidArgumentException('userAgentSuffix must be a non-empty printable ASCII string.');
        }

        $userAgent = sprintf('%s/%s PHP/%s', CloudClient::USER_AGENT_PRODUCT, CloudClient::VERSION, PHP_VERSION);
        $this->userAgent = $userAgentSuffix === null ? $userAgent : $userAgent . ' ' . $userAgentSuffix;

        $url = $this->normalizeUrl($url);
        $unusable = $url instanceof Secret ? null : $url;
        $this->url = $url instanceof Secret ? $url : null;

        try {
            $httpClient ??= $this->buildHttpClient($timeout);
            $requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
            $streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();
        } catch (Exception) {
            $unusable ??= 'No PSR-18 HTTP client or PSR-17 factory was found; install guzzlehttp/guzzle or pass one in.';
        }

        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->unusable = $unusable;
    }

    public function start(?PingDetails $details = null): PingResult
    {
        return $this->send('start', $details);
    }

    public function success(?PingDetails $details = null): PingResult
    {
        return $this->send('', $details);
    }

    public function fail(?PingDetails $details = null): PingResult
    {
        return $this->send('fail', $details);
    }

    public function exitCode(int $code, ?PingDetails $details = null): PingResult
    {
        return $this->send($code < 0 ? 'fail' : (string) $code, $details);
    }

    /** The configured timeout in seconds (see the class description for when it applies). */
    public function timeout(): float
    {
        return $this->timeout;
    }

    /**
     * @return array{url: string, timeout: float, httpClient: string|null}
     */
    public function __debugInfo(): array
    {
        return [
            'url' => '[redacted]',
            'timeout' => $this->timeout,
            'httpClient' => $this->httpClient instanceof ClientInterface ? $this->httpClient::class : null,
        ];
    }

    /**
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException('A heartbeat ping must not be serialized: its URL is a secret. Build it from configuration where it is needed.');
    }

    /**
     * @param string $signal `''` (success), `start`, `fail` or an exit code
     */
    private function send(string $signal, ?PingDetails $details): PingResult
    {
        $dropped = $details?->droppedMetrics() ?? [];

        if ($this->unusable !== null || !$this->url instanceof Secret || !$this->httpClient instanceof ClientInterface || !$this->requestFactory instanceof RequestFactoryInterface || !$this->streamFactory instanceof StreamFactoryInterface) {
            $error = $this->unusable ?? 'The ping is not usable.';
            $this->log($signal, null, null, $error);

            return PingResult::notSent($error, $dropped);
        }

        $started = hrtime(true);

        try {
            $request = $this->requestFactory
                ->createRequest('POST', $signal === '' ? $this->url->reveal() : $this->url->reveal() . '/' . $signal)
                ->withHeader('User-Agent', $this->userAgent);

            $body = $details?->toBody() ?? '';

            if ($body !== '') {
                $request = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streamFactory->createStream($body));
            }

            $status = $this->httpClient->sendRequest($request)->getStatusCode();
        } catch (Exception $e) {
            // The exception is not kept: a PSR-18 exception holds the request, URL included.
            $error = sprintf('No response: %s', $this->redact($e->getMessage()));
            $this->log($signal, null, $started, $error);

            // Only a network error says the way to LIVCK failed; anything else the client
            // throws (a broken middleware, a request it refuses) is a fault on this side.
            return $e instanceof NetworkExceptionInterface
                ? PingResult::noResponse($error, $dropped)
                : PingResult::notSent($error, $dropped);
        }

        $result = PingResult::fromStatus($status, $dropped);
        $this->log($signal, $status, $started, $result->error);

        return $result;
    }

    /**
     * The URL as a secret without a trailing slash, or the reason it cannot be used. The
     * reason never quotes the URL.
     */
    private function normalizeUrl(#[SensitiveParameter] string|Secret $url): Secret|string
    {
        $value = rtrim(trim($url instanceof Secret ? $url->reveal() : $url), '/');

        if ($value === '') {
            return 'The ping URL is empty.';
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return 'The ping URL is not an absolute URL with a path, such as https://ping.livck.cloud/<token>.';
        }

        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return 'The ping URL must not carry a query string, a fragment or credentials.';
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ($scheme !== 'http' || ! LoopbackHost::matches($parts['host']))) {
            return 'The ping URL must use https; plain http is accepted for loopback hosts only.';
        }

        return $url instanceof Secret && $value === $url->reveal() ? $url : new Secret($value);
    }

    private function buildHttpClient(float $timeout): ClientInterface
    {
        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client([
                'timeout' => $timeout,
                'connect_timeout' => $timeout,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        }

        return Psr18ClientDiscovery::find();
    }

    /**
     * The text with the URL and its token (the last path segment) replaced, for an HTTP
     * client's exception message that may quote them.
     */
    private function redact(string $text): string
    {
        if (!$this->url instanceof Secret) {
            return $text;
        }

        $url = $this->url->reveal();
        $token = substr($url, (int) strrpos($url, '/') + 1);
        $search = [$url, rawurlencode($url)];

        if (strlen($token) >= 8) {
            $search[] = $token;
        }

        return str_replace($search, '[redacted]', $text);
    }

    /**
     * One line per ping: debug when LIVCK accepted it, warning otherwise. Never the URL, never
     * a body.
     */
    private function log(string $signal, ?int $status, ?int $startedAt, ?string $error): void
    {
        $context = [
            'signal' => $signal === '' ? 'success' : $signal,
            'status' => $status ?? 'no response',
            'duration_ms' => $startedAt === null ? 0.0 : round((hrtime(true) - $startedAt) / 1_000_000, 1),
        ];

        if ($error === null) {
            $this->logger->debug('Heartbeat ping {signal} -> {status} in {duration_ms} ms', $context);

            return;
        }

        $this->logger->warning('Heartbeat ping {signal} failed: {error}', [...$context, 'error' => $error]);
    }
}
