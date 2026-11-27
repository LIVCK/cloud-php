<?php

declare(strict_types=1);

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Http\Response;
use LIVCK\Cloud\Ping\HeartbeatPing;
use LIVCK\Cloud\Ping\PingResult;
use LIVCK\Cloud\Support\Secret;
use LIVCK\Cloud\Testing\FakeHttpClient;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Tests\Support\RecordingLogger;

/**
 * A client wired to a FakeHttpClient, with retries kept (2) unless options say otherwise.
 *
 * @param list<MockResponse|Closure> $responses
 * @return array{0: CloudClient, 1: FakeHttpClient}
 */
function fakeClient(array $responses = [], ?ClientOptions $options = null): array
{
    return CloudClient::fake($responses, $options);
}

/**
 * A client that never retries, for tests about mapping rather than resilience.
 *
 * @param list<MockResponse|Closure> $responses
 * @return array{0: CloudClient, 1: FakeHttpClient}
 */
function singleShotClient(array $responses = []): array
{
    return CloudClient::fake($responses, new ClientOptions(maxRetries: 0));
}

/**
 * A Response built by hand, for unit tests below the transport.
 *
 * @param array<string, string|array<string>> $headers
 */
function response(int $status, string $body = '', array $headers = [], string $method = 'GET', string $uri = 'https://api.livck.cloud/v1/tags'): Response
{
    return new Response($status, $headers, $body, $method, $uri);
}

/**
 * A tag as the API returns it.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function tagPayload(array $overrides = []): array
{
    return [
        'id' => 'V1StGXR8Z5jdHi6BmyT01',
        'key' => 'kunde',
        'value' => '4711',
        'color' => '#6366f1',
        'label' => 'kunde:4711',
        'source' => 'user',
        'services_count' => 3,
        ...$overrides,
    ];
}

/**
 * The token part of the ping URL in the heartbeat tests.
 *
 * @return non-empty-string
 */
function pingToken(): string
{
    return 'hb_k3Jq9xWm2Lr7Tz5Vb8Nc1Pd4Fg6Hs0Ya2Ue5Wi9';
}

/**
 * A heartbeat ping URL as LIVCK hands it out.
 *
 * @return non-empty-string
 */
function pingUrl(): string
{
    return 'https://ping.livck.cloud/' . pingToken();
}

/**
 * A heartbeat ping wired to a FakeHttpClient.
 *
 * @param list<MockResponse> $responses
 * @return array{0: HeartbeatPing, 1: FakeHttpClient}
 */
function fakePing(array $responses = [], string|Secret|null $url = null, ?RecordingLogger $logger = null): array
{
    $http = new FakeHttpClient($responses);

    return [new HeartbeatPing($url ?? pingUrl(), httpClient: $http, logger: $logger ?? new RecordingLogger()), $http];
}

/**
 * Everything a ping could leak through after sending: its dumps, its result and its log lines.
 * `var_export()` of the ping itself is left out here: it also prints the FakeHttpClient, which
 * records what was sent (the URL included) on purpose.
 */
function pingTrace(HeartbeatPing $ping, PingResult $result, RecordingLogger $logger): string
{
    ob_start();
    var_dump($ping, $result);
    $dumped = (string) ob_get_clean();

    return $dumped . print_r($ping, true) . json_encode($ping) . json_encode($result) . var_export($result, true)
        . var_export($logger->records, true);
}
