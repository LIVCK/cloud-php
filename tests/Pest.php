<?php

declare(strict_types=1);

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Http\Response;
use LIVCK\Cloud\Testing\FakeHttpClient;
use LIVCK\Cloud\Testing\MockResponse;

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
