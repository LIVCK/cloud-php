<?php

declare(strict_types=1);

namespace LIVCK\Cloud;

use Closure;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\Me;
use LIVCK\Cloud\Http\BearerToken;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Response;
use LIVCK\Cloud\Http\RetryPolicy;
use LIVCK\Cloud\Http\Sleeper;
use LIVCK\Cloud\Http\SystemSleeper;
use LIVCK\Cloud\Http\Transport;
use LIVCK\Cloud\Resources\Discovery;
use LIVCK\Cloud\Resources\EnrollmentKeys;
use LIVCK\Cloud\Resources\EnrollmentKeysInterface;
use LIVCK\Cloud\Resources\Incidents;
use LIVCK\Cloud\Resources\IncidentsInterface;
use LIVCK\Cloud\Resources\Maintenances;
use LIVCK\Cloud\Resources\MaintenancesInterface;
use LIVCK\Cloud\Resources\Services;
use LIVCK\Cloud\Resources\ServicesInterface;
use LIVCK\Cloud\Resources\Statuspages;
use LIVCK\Cloud\Resources\StatuspagesInterface;
use LIVCK\Cloud\Resources\Tags;
use LIVCK\Cloud\Resources\TagsInterface;
use LIVCK\Cloud\Testing\FakeHttpClient;
use LIVCK\Cloud\Testing\MockResponse;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * Entry point to the LIVCK Cloud API v1.
 *
 *     $client = new CloudClient($token);
 *     $tag = $client->tags()->ensure('customer', '4711')->tag;
 *
 * Immutable and free of global state: safe as a singleton in long-running workers
 * (Octane, RoadRunner, queue workers). `withOptions()`, `withLocale()` and
 * `withHttpClient()` return new instances.
 *
 * Without an HTTP client, Guzzle is used when installed (with the configured timeouts,
 * redirects off, no exceptions for error statuses); otherwise whatever PSR-18 client
 * php-http/discovery finds, in which case the timeout options do not apply. PSR-17
 * factories are discovered the same way.
 *
 * The token never appears in dumps (`var_dump`, `print_r`, `var_export`), in log lines or
 * in exception messages.
 */
final class CloudClient implements CloudClientInterface
{
    public const string VERSION = '1.2.0';

    public const string USER_AGENT_PRODUCT = 'livck-cloud-php';

    private readonly BearerToken $token;

    private readonly ClientOptions $options;

    private readonly ClientInterface $httpClient;

    /** Whether the SDK built the HTTP client itself (and may rebuild it for new options). */
    private readonly bool $ownsHttpClient;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly Sleeper $sleeper;

    private readonly Transport $transport;

    private ?Tags $tags = null;

    private ?Statuspages $statuspages = null;

    private ?Services $services = null;

    private ?Incidents $incidents = null;

    private ?Maintenances $maintenances = null;

    private ?EnrollmentKeys $enrollmentKeys = null;

    private ?Discovery $discovery = null;

    /**
     * @param string|BearerToken $token the organization's API token (`lvk_…`)
     * @param Sleeper|null $sleeper where retries wait; defaults to real sleeping, or to the
     *                              recording sleeper of a {@see FakeHttpClient}
     */
    public function __construct(
        #[SensitiveParameter]
        string|BearerToken $token,
        ?ClientOptions $options = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?Sleeper $sleeper = null,
    ) {
        $this->token = $token instanceof BearerToken ? $token : new BearerToken($token);
        $this->options = $options ?? new ClientOptions();
        $this->ownsHttpClient = !$httpClient instanceof ClientInterface;
        $this->httpClient = $httpClient ?? $this->buildHttpClient($this->options);
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->sleeper = $sleeper ?? ($this->httpClient instanceof FakeHttpClient ? $this->httpClient->sleeper() : new SystemSleeper());

        $this->transport = new Transport(
            $this->token,
            $this->options,
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            RetryPolicy::fromOptions($this->options),
            $this->sleeper,
            $this->userAgent($this->options),
        );
    }

    /**
     * A client wired to a {@see FakeHttpClient}: nothing leaves the process, retries do
     * not sleep, and every request is recorded for assertions.
     *
     *     [$client, $http] = CloudClient::fake([MockResponse::json(['data' => [...]])]);
     *
     * @param iterable<MockResponse|Closure(RequestInterface): MockResponse> $responses answered in order
     * @return array{0: self, 1: FakeHttpClient}
     */
    public static function fake(iterable $responses = [], ?ClientOptions $options = null): array
    {
        $http = new FakeHttpClient($responses);

        return [new self('lvk_test_token', $options, $http, sleeper: $http->sleeper()), $http];
    }

    public function options(): ClientOptions
    {
        return $this->options;
    }

    public function withOptions(ClientOptions $options): static
    {
        return new self(
            $this->token,
            $options,
            $this->ownsHttpClient ? null : $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->sleeper,
        );
    }

    public function withLocale(?string $locale): static
    {
        return $this->withOptions($this->options->withLocale($locale));
    }

    public function withHttpClient(ClientInterface $httpClient): static
    {
        return new self($this->token, $this->options, $httpClient, $this->requestFactory, $this->streamFactory, $this->sleeper);
    }

    public function tags(): TagsInterface
    {
        return $this->tags ??= new Tags($this->transport);
    }

    public function statuspages(): StatuspagesInterface
    {
        return $this->statuspages ??= new Statuspages($this->transport);
    }

    public function services(): ServicesInterface
    {
        return $this->services ??= new Services($this->transport);
    }

    public function incidents(): IncidentsInterface
    {
        return $this->incidents ??= new Incidents($this->transport);
    }

    public function maintenances(): MaintenancesInterface
    {
        return $this->maintenances ??= new Maintenances($this->transport);
    }

    public function enrollmentKeys(): EnrollmentKeysInterface
    {
        return $this->enrollmentKeys ??= new EnrollmentKeys($this->transport);
    }

    public function me(): Me
    {
        return $this->discovery()->me();
    }

    public function probes(): array
    {
        return $this->discovery()->probes();
    }

    public function checkTypes(): CheckTypeCatalog
    {
        return $this->discovery()->checkTypes();
    }

    private function discovery(): Discovery
    {
        return $this->discovery ??= new Discovery($this->transport);
    }

    public function request(string $method, string $path, array $query = [], ?array $json = null, array $headers = []): Response
    {
        $request = Request::make($method, $path)->withQuery($query);

        if ($json !== null) {
            $request = $request->withJson($json);
        }

        $idempotencyKey = null;

        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Idempotency-Key') === 0) {
                $idempotencyKey = $value;
                unset($headers[$name]);
            }
        }

        $request = $request->withHeaders($headers);

        if ($idempotencyKey !== null) {
            $request = $request->withIdempotencyKey($idempotencyKey);
        }

        return $this->transport->send($request);
    }

    public function send(Request $request): Response
    {
        return $this->transport->send($request);
    }

    /**
     * @return array{options: ClientOptions, httpClient: string, token: string}
     */
    public function __debugInfo(): array
    {
        return [
            'options' => $this->options,
            'httpClient' => $this->httpClient::class,
            'token' => '[redacted]',
        ];
    }

    private function buildHttpClient(ClientOptions $options): ClientInterface
    {
        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client([
                'timeout' => $options->timeout,
                'connect_timeout' => $options->connectTimeout,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        }

        return Psr18ClientDiscovery::find();
    }

    private function userAgent(ClientOptions $options): string
    {
        $userAgent = sprintf('%s/%s PHP/%s', self::USER_AGENT_PRODUCT, self::VERSION, PHP_VERSION);

        return $options->userAgentSuffix === null ? $userAgent : $userAgent . ' ' . $options->userAgentSuffix;
    }
}
