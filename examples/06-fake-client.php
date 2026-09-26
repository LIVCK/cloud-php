<?php

declare(strict_types=1);

/*
 * Test your own integration code without a network. CloudClient::fake() answers from a queue
 * of MockResponses, records every request and never sleeps between retries. The checks below
 * are plain closures so the script runs anywhere; in PHPUnit or Pest each is a test case.
 *
 *   php examples/06-fake-client.php
 *
 * No token needed.
 */

use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Exceptions\PlanLimitException;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

require __DIR__ . '/bootstrap.php';

/** The code under test: the customer's tag, then an HTTP check of their website carrying it. */
function addWebsite(CloudClientInterface $client, string $customer, string $url): Service
{
    $tag = $client->tags()->ensure('customer', $customer)->tag;

    return $client->services()->create(ServiceBuilder::http($url, $url)->tags($tag));
}

const TAG_ID = 'V1StGXR8Z5jdHi6BmyT2a';

/**
 * A tag as the API returns it.
 *
 * @return array<string, mixed>
 */
function tagPayload(): array
{
    return [
        'id' => TAG_ID,
        'key' => 'customer',
        'value' => '4711',
        'color' => '#6366f1',
        'label' => 'customer:4711',
        'source' => 'user',
        'services_count' => 0,
    ];
}

/**
 * A freshly created service as the API returns it.
 *
 * @return array<string, mixed>
 */
function servicePayload(string $id = 'iChkaXWKTdPJxp7dJaDwn'): array
{
    $tag = tagPayload();
    unset($tag['services_count']);

    return [
        'id' => $id,
        'name' => 'https://www.example.com',
        'check_type' => 'http',
        'check_type_label' => 'HTTP/HTTPS',
        'target' => 'https://www.example.com',
        'status' => 'unknown',
        'effective_status' => 'unknown',
        'status_override' => null,
        'status_override_reason' => null,
        'status_override_at' => null,
        'favicon_url' => null,
        'is_paused' => false,
        'paused_reason' => null,
        'configured_at' => '2026-09-26T08:00:00+00:00',
        'is_configured' => true,
        'uptime_30d' => null,
        'avg_response_ms' => null,
        'tags' => [$tag],
        'last_check_at' => null,
        'created_at' => '2026-09-26T08:00:00+00:00',
        'settings' => [
            'interval_seconds' => 60,
            'timeout_seconds' => 10,
            'retries' => 2,
            'assigned_probes' => null,
            'probe_roles' => null,
            'config' => ['method' => 'GET'],
        ],
    ];
}

function check(bool $condition, string $expectation): void
{
    if (! $condition) {
        throw new AssertionError($expectation);
    }
}

$tests = [
    'creates the tag first, then the service carrying it' => static function (): void {
        [$client, $http] = CloudClient::fake([
            MockResponse::json(['data' => tagPayload()], 201),
            MockResponse::json(['data' => servicePayload()], 201),
        ]);

        $service = addWebsite($client, '4711', 'https://www.example.com');

        check($service->hasTag('customer:4711'), 'the service carries the customer tag');
        $http->assertSentCount(2)
            ->assertSent(static fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/tags/ensure')
                && $r->json() === ['key' => 'customer', 'value' => '4711'])
            ->assertSent(static fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/services')
                && ($r->json()['tags'] ?? null) === [TAG_ID]);
    },

    'a used-up plan surfaces as PlanLimitException' => static function (): void {
        [$client] = CloudClient::fake([
            MockResponse::json(['data' => tagPayload()]),
            MockResponse::error("Your plan's service limit has been reached.", 403, extra: [
                'limit' => 150,
                'usage' => 150,
                'upsell' => ['reason' => 'limit', 'key' => 'services'],
            ]),
        ]);

        try {
            addWebsite($client, '4711', 'https://www.example.com');
            check(false, 'a PlanLimitException was thrown');
        } catch (PlanLimitException $e) {
            check($e->limitKey() === 'services' && $e->usage() === 150, 'it names the limit and the usage');
        }
    },

    'a dropped connection is retried with the same Idempotency-Key' => static function (): void {
        [$client, $http] = CloudClient::fake([
            MockResponse::json(['data' => tagPayload()]),
            MockResponse::networkError('Connection reset by peer'),
            MockResponse::json(['data' => servicePayload()], 201)->replayed(),
        ]);

        addWebsite($client, '4711', 'https://www.example.com');

        $creates = array_values(array_filter(
            $http->recorded(),
            static fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/services'),
        ));

        check(count($creates) === 2, 'the create went out twice');
        check($creates[0]->idempotencyKey() !== null && $creates[0]->idempotencyKey() === $creates[1]->idempotencyKey(), 'both attempts carry one key');
        check(count($http->delays()) === 1, 'the backoff was recorded, not slept');
    },

    'each() loads the next page only when the loop gets there' => static function (): void {
        [$client, $http] = CloudClient::fake([
            MockResponse::page([servicePayload()], currentPage: 1, lastPage: 2, perPage: 1),
            MockResponse::page([servicePayload('4xYd0WPRhKDrDd2xPKRfe')], currentPage: 2, lastPage: 2, perPage: 1),
        ]);

        $services = $client->services()->each(ServiceQuery::make()->withTag('customer:4711'));
        $services->current();
        $http->assertSentCount(1);

        $ids = array_map(static fn(Service $service): string => $service->id, iterator_to_array($services, false));

        check($ids === ['iChkaXWKTdPJxp7dJaDwn', '4xYd0WPRhKDrDd2xPKRfe'], 'both pages were read');
        $http->assertSent(static fn(RecordedRequest $r): bool => $r->query() === ['tag' => 'customer:4711', 'page' => '2'])
            ->assertNoPendingResponses();
    },
];

$failed = 0;

foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'ok    ', $name, PHP_EOL;
    } catch (Throwable $e) {
        $failed++;
        echo 'FAIL  ', $name, ': ', $e->getMessage(), PHP_EOL;
    }
}

exit($failed === 0 ? 0 : 1);
