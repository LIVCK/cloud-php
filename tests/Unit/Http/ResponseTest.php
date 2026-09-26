<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Http\Response;

it('reads headers case-insensitively and keeps every value', function (): void {
    $response = response(200, '', ['Content-Type' => 'application/json', 'X-Multi' => ['a', 'b']]);

    expect($response->header('content-type'))->toBe('application/json')
        ->and($response->headerValues('x-multi'))->toBe(['a', 'b'])
        ->and($response->hasHeader('X-MULTI'))->toBeTrue()
        ->and($response->header('missing'))->toBeNull()
        ->and($response->headers())->toBe(['content-type' => ['application/json'], 'x-multi' => ['a', 'b']])
        ->and($response->contentType())->toBe('application/json');
});

it('decodes a JSON object once and refuses anything else', function (): void {
    expect(response(200, '{"data":{"id":"a"}}')->json())->toBe(['data' => ['id' => 'a']])
        ->and(response(200, '{}')->json())->toBe([]);

    expect(fn(): array => response(200, 'not json')->json())
        ->toThrow(UnexpectedResponseException::class, 'not valid JSON');

    expect(fn(): array => response(200, '[1,2]')->json())
        ->toThrow(UnexpectedResponseException::class, 'JSON array');

    expect(fn(): array => response(200, '"scalar"')->json())
        ->toThrow(UnexpectedResponseException::class, 'string');
});

it('names status, method and URI when the body cannot be read', function (): void {
    try {
        response(502, '<html>Bad Gateway</html>', [], 'GET', 'https://api.livck.cloud/v1/tags')->json();
        expect(false)->toBeTrue('an exception was expected');
    } catch (UnexpectedResponseException $e) {
        expect($e->getMessage())->toContain('HTTP 502')
            ->and($e->getMessage())->toContain('GET https://api.livck.cloud/v1/tags')
            ->and($e->response()?->status())->toBe(502);
    }
});

it('exposes the rate limit headers', function (): void {
    $response = response(429, '', [
        'X-RateLimit-Limit' => '120',
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => '784111777',
        'Retry-After' => '17',
    ]);

    $limit = $response->rateLimit();

    expect($limit?->limit)->toBe(120)
        ->and($limit?->remaining)->toBe(0)
        ->and($limit?->isExhausted())->toBeTrue()
        ->and($limit?->resetsAt?->format('Y-m-d H:i:s'))->toBe('1994-11-06 08:49:37')
        ->and($limit?->retryAfter)->toBe(17)
        ->and($response->retryAfter())->toBe(17)
        ->and(response(200)->rateLimit())->toBeNull()
        ->and(response(200)->retryAfter())->toBeNull();
});

it('parses an HTTP-date Retry-After against a given clock', function (): void {
    $now = new DateTimeImmutable('1994-11-06 08:49:00', new DateTimeZone('UTC'));

    expect(response(503, '', ['Retry-After' => 'Sun, 06 Nov 1994 08:49:30 GMT'])->retryAfter($now))->toBe(30);
});

it('recognises a replayed response and reports success for 2xx only', function (): void {
    expect(response(201, '', ['Idempotent-Replayed' => 'true'])->wasReplayed())->toBeTrue()
        ->and(response(201)->wasReplayed())->toBeFalse()
        ->and(response(204)->isSuccessful())->toBeTrue()
        ->and(response(302)->isSuccessful())->toBeFalse()
        ->and(response(404)->isSuccessful())->toBeFalse();
});

it('detaches from the PSR-7 message', function (): void {
    $psr = new PsrResponse(201, ['Location' => '/v1/tags/a'], '{"data":[]}');

    $response = Response::fromPsr($psr, 'POST', 'https://api.livck.cloud/v1/tags');

    expect($response->status())->toBe(201)
        ->and($response->reasonPhrase())->toBe('Created')
        ->and($response->header('location'))->toBe('/v1/tags/a')
        ->and($response->body())->toBe('{"data":[]}')
        ->and($response->requestMethod())->toBe('POST')
        ->and($response->requestUri())->toBe('https://api.livck.cloud/v1/tags');
});
