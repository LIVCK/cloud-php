<?php

declare(strict_types=1);

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\RateLimitException;
use LIVCK\Cloud\Exceptions\ServiceUnavailableException;
use LIVCK\Cloud\Exceptions\TransportException;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use LIVCK\Cloud\Tests\Support\RecordingLogger;

/**
 * @return array<string, mixed>|null
 */
function bodyFor(string $method): ?array
{
    return in_array($method, ['POST', 'PUT', 'PATCH'], true) ? ['key' => 'k'] : null;
}

describe('retry matrix', function (): void {
    it('sends the expected number of attempts for a status', function (string $method, int $status, int $attempts): void {
        $error = MockResponse::error('Failed.', $status);
        [$client, $http] = fakeClient([$error, $error, $error]);

        expect(fn() => $client->request($method, 'tags', json: bodyFor($method)))->toThrow(ApiException::class);

        expect($http->recorded())->toHaveCount($attempts)
            ->and($http->delays())->toHaveCount($attempts - 1);
    })->with([
        'GET 429' => ['GET', 429, 3],
        'POST 429' => ['POST', 429, 3],
        'PATCH 429' => ['PATCH', 429, 3],
        'DELETE 429' => ['DELETE', 429, 3],
        'GET 502' => ['GET', 502, 3],
        'HEAD 503' => ['HEAD', 503, 3],
        'PUT 504' => ['PUT', 504, 3],
        'DELETE 503' => ['DELETE', 503, 3],
        'POST 502' => ['POST', 502, 1],
        'POST 503' => ['POST', 503, 1],
        'POST 504' => ['POST', 504, 1],
        'PATCH 503' => ['PATCH', 503, 1],
        'GET 500' => ['GET', 500, 1],
        'GET 422' => ['GET', 422, 1],
        'GET 404' => ['GET', 404, 1],
        'POST 409 state conflict' => ['POST', 409, 1],
        'GET 401' => ['GET', 401, 1],
    ]);

    it('retries the idempotency-in-progress 409 (Retry-After + the key it sent) and nothing else that is a 409', function (): void {
        $inProgress = MockResponse::error('Still processing.', 409)->withRetryAfter(1);

        [$client, $http] = fakeClient([$inProgress, MockResponse::json(['data' => []], 201)]);
        $client->request('POST', 'tags', json: ['key' => 'k']);

        expect($http->recorded())->toHaveCount(2)
            ->and($http->delays())->toBe([1.0]);

        // Same response, but the POST carried no key: nothing to replay, no retry.
        [$client, $http] = fakeClient([$inProgress], new ClientOptions(idempotency: false));
        expect(fn() => $client->request('POST', 'tags', json: ['key' => 'k']))->toThrow(ApiException::class);
        expect($http->recorded())->toHaveCount(1);

        // A GET never carries a key, so a 409 with Retry-After is final for it.
        [$client, $http] = fakeClient([$inProgress]);
        expect(fn() => $client->request('GET', 'tags'))->toThrow(ApiException::class);
        expect($http->recorded())->toHaveCount(1);
    });

    it('retries transport errors for every method except a keyless POST or PATCH', function (string $method, ?ClientOptions $options, int $attempts): void {
        $down = MockResponse::networkError('Connection refused');
        [$client, $http] = fakeClient([$down, $down, $down], $options);

        expect(fn() => $client->request($method, 'tags', json: bodyFor($method)))->toThrow(TransportException::class);

        expect($http->recorded())->toHaveCount($attempts);
    })->with([
        'GET' => ['GET', null, 3],
        'DELETE' => ['DELETE', null, 3],
        'PUT' => ['PUT', null, 3],
        'POST with key' => ['POST', null, 3],
        'PATCH, idempotency on' => ['PATCH', null, 3],
        'POST, idempotency off' => ['POST', new ClientOptions(idempotency: false), 1],
        'PATCH, idempotency off' => ['PATCH', new ClientOptions(idempotency: false), 1],
    ]);

    it('does not retry at all with maxRetries 0', function (): void {
        [$client, $http] = fakeClient([MockResponse::error('Slow down.', 429)], new ClientOptions(maxRetries: 0));

        expect(fn() => $client->request('GET', 'tags'))->toThrow(RateLimitException::class);
        expect($http->recorded())->toHaveCount(1)
            ->and($http->delays())->toBe([]);
    });
});

describe('outcome after retries', function (): void {
    it('returns the first successful response', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::error('Gateway down.', 503),
            MockResponse::networkError(),
            MockResponse::json(['ok' => true]),
        ]);

        expect($client->request('GET', 'tags')->json())->toBe(['ok' => true])
            ->and($http->recorded())->toHaveCount(3)
            ->and($http->delays())->toHaveCount(2);
    });

    it('reports the attempts in the transport exception, without chaining the raw client exception', function (): void {
        $down = MockResponse::networkError('Could not resolve host');
        [$client] = fakeClient([$down, $down, $down]);

        try {
            $client->request('GET', 'tags');
            expect(false)->toBeTrue('a TransportException was expected');
        } catch (TransportException $e) {
            expect($e->attempts())->toBe(3)
                ->and($e->requestMethod())->toBe('GET')
                ->and($e->requestUri())->toBe('https://api.livck.cloud/v1/tags')
                ->and($e->underlyingClass())->toContain('FakeNetworkException')
                ->and($e->getMessage())->toContain('Could not resolve host')
                ->and($e->getPrevious())->toBeNull();
        }
    });

    it('scrubs the token from a client exception message', function (): void {
        [$client] = singleShotClient([MockResponse::networkError('failed sending "Bearer lvk_test_token"')]);

        try {
            $client->request('GET', 'tags');
            expect(false)->toBeTrue('a TransportException was expected');
        } catch (TransportException $e) {
            expect($e->getMessage())->not->toContain('lvk_test_token')
                ->and($e->getMessage())->toContain('[redacted]');
        }
    });
});

describe('idempotency across retries', function (): void {
    it('reuses the generated key for every attempt of one call', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::networkError(),
            MockResponse::error('Slow down.', 429)->withRetryAfter(2),
            MockResponse::json(['data' => []], 201),
        ]);

        $client->request('POST', 'tags', json: ['key' => 'k']);

        $keys = array_unique(array_map(fn(RecordedRequest $r): ?string => $r->idempotencyKey(), $http->recorded()));

        expect($http->recorded())->toHaveCount(3)
            ->and($keys)->toHaveCount(1)
            ->and($keys[0])->toBeString();
    });

    it('reuses a caller-supplied key the same way', function (): void {
        [$client, $http] = fakeClient([MockResponse::networkError(), MockResponse::json(['data' => []], 201)]);

        $client->send(Request::post('tags', ['key' => 'k'])->withIdempotencyKey('order-4711'));

        expect($http->recorded()[0]->idempotencyKey())->toBe('order-4711')
            ->and($http->recorded()[1]->idempotencyKey())->toBe('order-4711');
    });

    it('re-sends the same JSON body on every attempt', function (): void {
        [$client, $http] = fakeClient([MockResponse::networkError(), MockResponse::json(['data' => []], 201)]);

        $client->request('POST', 'tags', json: ['key' => 'k']);

        expect($http->recorded()[0]->body)->toBe('{"key":"k"}')
            ->and($http->recorded()[1]->body)->toBe('{"key":"k"}');
    });
});

describe('Retry-After', function (): void {
    it('waits exactly what the server asked for on 429 and 503', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::error('Slow down.', 429)->withRetryAfter(3),
            MockResponse::error('History unavailable.', 503)->withRetryAfter(30),
            MockResponse::json(['ok' => true]),
        ]);

        $client->request('GET', 'services/abc/checks');

        expect($http->delays())->toBe([3.0, 30.0]);
    });

    it('waits at least a second when a truncated Retry-After says 0, instead of spending the retries at once', function (): void {
        // The server rounds the remaining window down to whole seconds: a client back exactly
        // on time can be a fraction early and is then told "0". That is not "retry now".
        [$client, $http] = fakeClient([
            MockResponse::error('Slow down.', 429)->withRetryAfter(52),
            MockResponse::error('Slow down.', 429)->withRetryAfter(0),
            MockResponse::json(['ok' => true]),
        ]);

        $client->request('GET', 'services');

        expect($http->delays())->toBe([52.0, 1.0]);
    });

    it('understands an HTTP-date', function (): void {
        $inFive = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+5 seconds');
        [$client, $http] = fakeClient([
            MockResponse::error('Slow down.', 429)->withRetryAfter($inFive->format(DATE_RFC7231)),
            MockResponse::json(['ok' => true]),
        ]);

        $client->request('GET', 'tags');

        expect($http->delays()[0])->toBeGreaterThanOrEqual(3.0)->toBeLessThanOrEqual(5.0);
    });

    it('fails fast instead of waiting longer than maxRetryAfter', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::error('Slow down.', 429)->withRetryAfter(120),
            MockResponse::json(['ok' => true]),
        ]);

        try {
            $client->request('GET', 'tags');
            expect(false)->toBeTrue('a RateLimitException was expected');
        } catch (RateLimitException $e) {
            expect($e->retryAfter())->toBe(120);
        }

        expect($http->recorded())->toHaveCount(1)
            ->and($http->delays())->toBe([]);
    });

    it('fails fast on a 503 the same way', function (): void {
        [$client, $http] = fakeClient([MockResponse::error('Frozen.', 503)->withRetryAfter(3600)], new ClientOptions(maxRetryAfter: 60));

        expect(fn() => $client->request('GET', 'tags'))->toThrow(ServiceUnavailableException::class);
        expect($http->recorded())->toHaveCount(1);
    });

    it('falls back to backoff when a 429 carries no Retry-After', function (): void {
        [$client, $http] = fakeClient([MockResponse::error('Slow down.', 429), MockResponse::json([])], new ClientOptions(backoffBase: 0.5, backoffCap: 8.0));

        $client->request('GET', 'tags');

        expect($http->delays())->toHaveCount(1)
            ->and($http->delays()[0])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(0.5);
    });
});

describe('DELETE retried after the first attempt went through', function (): void {
    it('treats a 404 on a retry as the success it was retried for', function (): void {
        [$client, $http] = fakeClient([MockResponse::networkError(), MockResponse::error('Not found.', 404)]);

        $response = $client->request('DELETE', 'tags/abc');

        expect($response->status())->toBe(404)
            ->and($http->recorded())->toHaveCount(2);
    });

    it('treats a 404 after a gateway error the same way', function (): void {
        [$client] = fakeClient([MockResponse::error('Bad gateway.', 502), MockResponse::error('Not found.', 404)]);

        expect($client->request('DELETE', 'tags/abc')->status())->toBe(404);
    });

    it('keeps a first-attempt 404 as not found', function (): void {
        [$client, $http] = fakeClient([MockResponse::error('Not found.', 404)]);

        expect(fn() => $client->request('DELETE', 'tags/abc'))->toThrow(NotFoundException::class);
        expect($http->recorded())->toHaveCount(1);
    });

    it('does not extend the rule to other methods', function (): void {
        [$client] = fakeClient([MockResponse::networkError(), MockResponse::error('Not found.', 404)]);

        expect(fn() => $client->request('GET', 'tags/abc'))->toThrow(NotFoundException::class);
    });
});

describe('logging', function (): void {
    it('writes one debug line per attempt without the token or bodies', function (): void {
        $logger = new RecordingLogger();
        [$client] = fakeClient([
            MockResponse::networkError(),
            MockResponse::error('Slow down.', 429)->withRetryAfter(1),
            MockResponse::json(['data' => ['secret_field' => 'never-logged']], 201)->replayed(),
        ], new ClientOptions(logger: $logger));

        $client->request('POST', 'tags', json: ['key' => 'body-value']);

        expect($logger->records)->toHaveCount(3);

        [$first, $second, $third] = $logger->records;

        expect($first['level'])->toBe('debug')
            ->and($first['context']['method'])->toBe('POST')
            ->and($first['context']['uri'])->toBe('https://api.livck.cloud/v1/tags')
            ->and($first['context']['status'])->toBe('transport error')
            ->and($first['context']['attempt'])->toBe(1)
            ->and($first['context']['max_attempts'])->toBe(3)
            ->and($first['context']['replayed'])->toBeFalse()
            ->and($first['context']['idempotency_key'])->toBeString()
            ->and($first['context']['duration_ms'])->toBeFloat()
            ->and($second['context']['status'])->toBe(429)
            ->and($second['context']['attempt'])->toBe(2)
            ->and($third['context']['status'])->toBe(201)
            ->and($third['context']['replayed'])->toBeTrue();

        $serialized = json_encode($logger->records, JSON_THROW_ON_ERROR);

        expect($serialized)->not->toContain('lvk_test_token')
            ->and($serialized)->not->toContain('Authorization')
            ->and($serialized)->not->toContain('body-value')
            ->and($serialized)->not->toContain('never-logged');
    });
});
