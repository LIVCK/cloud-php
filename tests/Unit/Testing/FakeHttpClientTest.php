<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Support\Field;
use LIVCK\Cloud\Support\Json;
use LIVCK\Cloud\Testing\ExpectationFailedException;
use LIVCK\Cloud\Testing\FakeHttpClient;
use LIVCK\Cloud\Testing\FakeNetworkException;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;

$factory = new HttpFactory();

it('answers queued responses in order and records every request', function () use ($factory): void {
    $http = new FakeHttpClient([MockResponse::json(['n' => 1]), MockResponse::noContent()]);
    $http->queue(MockResponse::raw('third', 202, ['X-Third' => 'yes']));

    $first = $http->sendRequest($factory->createRequest('GET', 'https://api.livck.cloud/v1/tags?probe[]=a&probe[]=b'));
    $second = $http->sendRequest($factory->createRequest('DELETE', 'https://api.livck.cloud/v1/tags/x'));
    $third = $http->sendRequest($factory->createRequest('POST', 'https://api.livck.cloud/v1/tags'));

    expect($first->getStatusCode())->toBe(200)
        ->and((string) $first->getBody())->toBe('{"n":1}')
        ->and($first->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($second->getStatusCode())->toBe(204)
        ->and($third->getStatusCode())->toBe(202)
        ->and($third->getHeaderLine('X-Third'))->toBe('yes')
        ->and((string) $third->getBody())->toBe('third')
        ->and($http->recorded())->toHaveCount(3)
        ->and($http->remaining())->toBe(0)
        ->and($http->lastRequest()?->method)->toBe('POST');

    $recorded = $http->recorded()[0];

    expect($recorded->path())->toBe('/v1/tags')
        ->and($recorded->query())->toBe(['probe' => ['a', 'b']])
        ->and($recorded->matches('get', '/v1/tags'))->toBeTrue()
        ->and($recorded->matches('GET', '/v1/tags/x'))->toBeFalse();
});

it('lets a closure decide the response from the request', function () use ($factory): void {
    $http = new FakeHttpClient([
        fn(RequestInterface $request): MockResponse => MockResponse::json(['method' => $request->getMethod()]),
    ]);

    $response = $http->sendRequest($factory->createRequest('PUT', 'https://api.livck.cloud/v1/x'));

    expect((string) $response->getBody())->toBe('{"method":"PUT"}');
});

it('raises a PSR-18 network exception for a network error', function () use ($factory): void {
    $http = new FakeHttpClient([MockResponse::networkError('Connection timed out')]);
    $request = $factory->createRequest('GET', 'https://api.livck.cloud/v1/x');

    try {
        $http->sendRequest($request);
        expect(false)->toBeTrue('an exception was expected');
    } catch (FakeNetworkException $e) {
        expect($e->getMessage())->toBe('Connection timed out')
            ->and($e->getRequest())->toBe($request);
    }
});

it('refuses to answer when the queue is empty', function () use ($factory): void {
    $http = new FakeHttpClient();

    expect(fn() => $http->sendRequest($factory->createRequest('GET', 'https://api.livck.cloud/v1/x')))
        ->toThrow(LogicException::class, 'no response left for GET https://api.livck.cloud/v1/x');
});

it('exposes body and headers of a recorded request', function () use ($factory): void {
    $http = new FakeHttpClient([MockResponse::json([])]);
    $request = $factory->createRequest('POST', 'https://api.livck.cloud/v1/tags')
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('Idempotency-Key', 'k1')
        ->withBody($factory->createStream('{"key":"kunde"}'));

    $http->sendRequest($request);

    $recorded = $http->lastRequest();

    expect($recorded?->isJson())->toBeTrue()
        ->and($recorded?->json())->toBe(['key' => 'kunde'])
        ->and($recorded?->idempotencyKey())->toBe('k1')
        ->and($recorded?->header('content-type'))->toBe('application/json')
        ->and($recorded?->hasHeader('X-None'))->toBeFalse()
        ->and($request->getBody()->tell())->toBe(0);

    $bare = RecordedRequest::fromPsr($factory->createRequest('GET', 'https://api.livck.cloud/v1/tags'));

    expect($bare->json())->toBeNull()
        ->and($bare->isJson())->toBeFalse();
});

describe('assertions', function () use ($factory): void {
    it('pass when a matching request was sent and fail otherwise', function () use ($factory): void {
        $http = new FakeHttpClient([MockResponse::json([])]);
        $http->sendRequest($factory->createRequest('GET', 'https://api.livck.cloud/v1/tags'));

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/tags'))
            ->assertNotSent(fn(RecordedRequest $r): bool => $r->method === 'POST')
            ->assertSentCount(1)
            ->assertNoPendingResponses();

        expect(fn() => $http->assertSent(fn(RecordedRequest $r): bool => $r->method === 'POST'))
            ->toThrow(ExpectationFailedException::class, 'No request matched');

        expect(fn() => $http->assertNotSent(fn(RecordedRequest $r): bool => $r->method === 'GET'))
            ->toThrow(ExpectationFailedException::class, 'should not have been met');

        expect(fn() => $http->assertSentCount(2))
            ->toThrow(ExpectationFailedException::class, 'Expected 2 request(s), 1 were sent');

        expect(fn() => $http->assertNothingSent())
            ->toThrow(ExpectationFailedException::class, 'Expected no request');

        expect(fn() => $http->assertSent(fn(): bool => false, 'custom message'))
            ->toThrow(ExpectationFailedException::class, 'custom message');
    });

    it('report responses that were queued but never requested', function (): void {
        $http = new FakeHttpClient([MockResponse::json([])]);

        $http->assertNothingSent();

        expect(fn() => $http->assertNoPendingResponses())
            ->toThrow(ExpectationFailedException::class, '1 queued response(s)');
    });

    it('are failures, not errors, for PHPUnit', function (): void {
        expect(new ExpectationFailedException('x'))->toBeInstanceOf(AssertionError::class);
    });
});

describe('MockResponse', function (): void {
    it('builds the error envelope', function (): void {
        $mock = MockResponse::error('Invalid.', 422, ['key' => ['Required.']], ['requires_confirmation' => true], ['X-A' => '1']);

        expect($mock->status)->toBe(422)
            ->and($mock->headers)->toBe(['Content-Type' => 'application/json', 'X-A' => '1'])
            ->and($mock->body)->toBe('{"message":"Invalid.","errors":{"key":["Required."]},"requires_confirmation":true}');
    });

    it('builds paginated envelopes in the API shape', function (): void {
        $page = Json::decode(MockResponse::page([['id' => 'a']], currentPage: 2, lastPage: 3, perPage: 1)->body);
        $cursor = Json::decode(MockResponse::cursorPage([['id' => 'c']], 'next')->body);
        $pageLinks = Field::object($page, 'links');
        $cursorLinks = Field::object($cursor, 'links');

        expect(Field::object($page, 'meta'))->toBe(['current_page' => 2, 'from' => 2, 'last_page' => 3, 'links' => [], 'path' => 'https://api.livck.cloud/v1/list', 'per_page' => 1, 'to' => 2, 'total' => 3])
            ->and($pageLinks['next'])->toBe('https://api.livck.cloud/v1/list?page=3')
            ->and($pageLinks['prev'])->toBe('https://api.livck.cloud/v1/list?page=1')
            ->and(Field::object($cursor, 'meta'))->toBe(['per_page' => 50, 'next_cursor' => 'next'])
            ->and($cursorLinks['next'])->toContain('cursor=next');
    });

    it('adds headers fluently', function (): void {
        $mock = MockResponse::json([])->replayed()->withRetryAfter(5)->withRateLimit(120, 3, 99)->withHeader('X-A', 'b');

        expect($mock->headers)->toBe([
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
            'Retry-After' => '5',
            'X-RateLimit-Limit' => '120',
            'X-RateLimit-Remaining' => '3',
            'X-RateLimit-Reset' => '99',
            'X-A' => 'b',
        ])->and($mock->isNetworkError())->toBeFalse()
            ->and(MockResponse::networkError()->isNetworkError())->toBeTrue();
    });
});

it('counts a met expectation as a PHPUnit assertion', function (): void {
    [$client, $http] = CloudClient::fake([MockResponse::noContent()]);
    $client->request('DELETE', 'tags/abc');

    $before = Assert::getCount();
    $http->assertSent(static fn(RecordedRequest $request): bool => $request->method === 'DELETE')
        ->assertNotSent(static fn(RecordedRequest $request): bool => $request->method === 'POST')
        ->assertSentCount(1)
        ->assertNoPendingResponses();
    $after = Assert::getCount();

    expect($after - $before)->toBe(4);
});
