<?php

declare(strict_types=1);

/*
 * The SDK's exceptions in practice: what each one means and what to read from it. The live
 * scenarios fail on purpose and change nothing. A plan limit or the rate limit would take an
 * exhausted quota to provoke, so those two replay the API's answers through the fake client.
 * At the end, request() reaches an endpoint the SDK does not wrap.
 *
 *   php examples/05-error-handling.php
 *
 * Token abilities: services.view and services.create. Without oncall.view (the usual case
 * for a provisioning token) the permission scenario is refused, as intended.
 */

use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Exceptions\CatalogValidationException;
use LIVCK\Cloud\Exceptions\FeatureNotAvailableException;
use LIVCK\Cloud\Exceptions\LivckCloudException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\PlanLimitException;
use LIVCK\Cloud\Exceptions\RateLimitException;
use LIVCK\Cloud\Exceptions\ServerException;
use LIVCK\Cloud\Exceptions\TransportException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Testing\MockResponse;

require __DIR__ . '/bootstrap.php';

/** What your code would log or show for each exception. */
function explain(LivckCloudException $e): string
{
    return match (true) {
        // Found on the client, nothing was sent.
        $e instanceof CatalogValidationException => implode(' ', $e->problems()),
        // 422. Some plan limits land here too, on the field: an interval below the plan's
        // minimum, too many locations, the tag cap, the number of status pages.
        $e instanceof ValidationException => sprintf(
            '%s [fields: %s]',
            $e->firstError() ?? $e->errorMessage(),
            $e->hasFieldErrors() ? implode(', ', array_keys($e->errors())) : 'none',
        ),
        // The plan's quota is used up: upgrade or free up room. Retrying does not help.
        $e instanceof PlanLimitException => sprintf(
            'plan limit "%s": %s of %s used',
            $e->limitKey() ?? '?',
            $e->usage() ?? '?',
            $e->limit() ?? '?',
        ),
        // The plan lacks a feature altogether (api_access below the Team plan).
        $e instanceof FeatureNotAvailableException => sprintf('the plan lacks "%s"', $e->featureKey() ?? '?'),
        // The token was minted without the ability: a new token fixes it, the plan does not.
        $e instanceof PermissionDeniedException => 'the token lacks the ability: ' . $e->errorMessage(),
        // Unknown, from another organization, or hidden from this token: the API does not say which.
        $e instanceof NotFoundException => $e->errorMessage(),
        // Retries already waited Retry-After where it was short enough.
        $e instanceof RateLimitException => sprintf('try again in %d s', $e->retryAfter() ?? 60),
        // Reads were retried; a write was not, so check its outcome before sending it again.
        $e instanceof ServerException => sprintf('HTTP %d: %s', $e->status(), $e->errorMessage()),
        // No response at all, after the retries that are safe.
        $e instanceof TransportException => sprintf('no response after %d attempt(s): %s', $e->attempts(), $e->underlyingClass()),
        default => $e->getMessage(),
    };
}

$client = client('05-error-handling');

// A client whose answers are canned: see 06-fake-client.php.
$replay = static fn(MockResponse $answer): CloudClient => CloudClient::fake([$answer])[0];

$scenarios = [
    'Typo in a config key, caught by the catalog' => static fn() => $client->services()->create(
        ServiceBuilder::http('Shop', 'https://www.example.com')->config('follow_redirect', true),
        $client->checkTypes(),
    ),
    'Interval below the plan minimum' => static fn() => $client->services()->create(
        ServiceBuilder::http('Shop', 'https://www.example.com')->interval(1),
    ),
    'Unknown service id' => static fn() => $client->services()->get('xxxxxxxxxxxxxxxxxxxxx'),
    'An endpoint the token has no ability for' => static fn() => $client->request('GET', 'oncall/schedules'),
    'Nobody listening' => static fn() => (new CloudClient('lvk_example', new ClientOptions(
        baseUri: 'http://127.0.0.1:9/v1',
        timeout: 2.0,
        connectTimeout: 1.0,
        maxRetries: 1,
        backoffBase: 0.1,
    )))->me(),
    'Service quota used up (replayed)' => static fn() => $replay(MockResponse::error(
        "Your plan's service limit has been reached.",
        403,
        extra: ['limit' => 150, 'usage' => 150, 'upsell' => ['reason' => 'limit', 'key' => 'services']],
    ))->services()->create(ServiceBuilder::http('Shop', 'https://www.example.com')),
    'Rate limit, Retry-After too long to wait (replayed)' => static fn() => $replay(
        MockResponse::error('Too Many Attempts.', 429)->withRetryAfter(90)->withRateLimit(120, 0),
    )->services()->list(),
];

foreach ($scenarios as $title => $scenario) {
    try {
        $scenario();
        printf("%s: no error\n", $title);
    } catch (LivckCloudException $e) {
        printf("%s\n  %s: %s\n", $title, (new ReflectionClass($e))->getShortName(), explain($e));
    }
}

// request() sends anything through the same transport (authentication, retries, error
// mapping), for what the SDK does not wrap yet, and returns the raw response: here the list
// total from the body and the rate-limit headers every response carries.
try {
    $response = $client->request('GET', 'services', ['per_page' => 1]);
    $meta = $response->json()['meta'] ?? null;
    $total = is_array($meta) ? ($meta['total'] ?? null) : null;
    $rateLimit = $response->rateLimit();

    printf(
        "\nrequest(): HTTP %d, %s services in the organization, %s of %s requests left this minute\n",
        $response->status(),
        is_int($total) ? $total : '?',
        $rateLimit === null ? '?' : $rateLimit->remaining,
        $rateLimit === null ? '?' : $rateLimit->limit,
    );
} catch (LivckCloudException $e) {
    fail($e->getMessage());
}
