<?php

declare(strict_types=1);

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Http\BearerToken;
use LIVCK\Cloud\Http\MultipartPart;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Support\Uuid;
use LIVCK\Cloud\Testing\FakeHttpClient;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

describe('headers', function (): void {
    it('sends the bearer token, a JSON accept header and a versioned user agent on every request', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['ok' => true])]);

        $client->request('GET', 'me');

        $sent = $http->lastRequest();

        expect($sent)->not->toBeNull()
            ->and($sent?->header('Authorization'))->toBe('Bearer lvk_test_token')
            ->and($sent?->header('Accept'))->toBe('application/json')
            ->and($sent?->header('User-Agent'))->toBe(sprintf('livck-cloud-php/%s PHP/%s', CloudClient::VERSION, PHP_VERSION))
            ->and($sent?->hasHeader('Content-Type'))->toBeFalse()
            ->and($sent?->hasHeader('Idempotency-Key'))->toBeFalse()
            ->and($sent?->hasHeader('Accept-Language'))->toBeFalse();
    });

    it('sends a JSON content type only with a JSON body', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => []], 201)]);

        $client->request('POST', 'tags', json: ['key' => 'kunde', 'value' => '4711']);

        $sent = $http->lastRequest();

        expect($sent?->header('Content-Type'))->toBe('application/json')
            ->and($sent?->body)->toBe('{"key":"kunde","value":"4711"}');
    });

    it('sends Accept-Language when a locale is configured', function (): void {
        [$client, $http] = fakeClient([MockResponse::json([])], new ClientOptions(locale: 'de'));

        $client->request('GET', 'tags');

        expect($http->lastRequest()?->header('Accept-Language'))->toBe('de');
    });

    it('appends the configured suffix to the user agent', function (): void {
        [$client, $http] = fakeClient([MockResponse::json([])], new ClientOptions(userAgentSuffix: 'hoster-panel/2.3'));

        $client->request('GET', 'tags');

        expect($http->lastRequest()?->header('User-Agent'))->toEndWith(' hoster-panel/2.3');
    });

    it('passes extra headers through and refuses the managed ones', function (): void {
        [$client, $http] = fakeClient([MockResponse::json([])]);

        $client->request('GET', 'tags', headers: ['X-Request-Id' => 'abc']);

        expect($http->lastRequest()?->header('X-Request-Id'))->toBe('abc');

        expect(fn() => $client->request('GET', 'tags', headers: ['Authorization' => 'Bearer other']))
            ->toThrow(InvalidArgumentException::class, 'Authorization');
    });
});

describe('idempotency keys', function (): void {
    it('generates one UUID v4 per POST', function (): void {
        [$client, $http] = fakeClient([MockResponse::json([]), MockResponse::json([])]);

        $client->request('POST', 'tags', json: ['key' => 'a']);
        $client->request('POST', 'tags', json: ['key' => 'b']);

        $keys = array_map(fn(RecordedRequest $r): ?string => $r->idempotencyKey(), $http->recorded());

        expect($keys[0])->toBeString()
            ->and(Uuid::isV4((string) $keys[0]))->toBeTrue()
            ->and(Uuid::isV4((string) $keys[1]))->toBeTrue()
            ->and($keys[0])->not->toBe($keys[1]);
    });

    it('never sends a key on GET, PUT, PATCH or DELETE', function (string $method): void {
        [$client, $http] = fakeClient([MockResponse::json([])]);

        $client->request($method, 'tags/abc', json: $method === 'GET' || $method === 'DELETE' ? null : ['color' => '#000000']);

        expect($http->lastRequest()?->hasHeader('Idempotency-Key'))->toBeFalse();
    })->with(['GET', 'PUT', 'PATCH', 'DELETE']);

    it('uses the key given in the headers of the escape hatch instead of a generated one', function (): void {
        [$client, $http] = fakeClient([MockResponse::json([])]);

        $client->request('POST', 'tags', json: ['key' => 'a'], headers: ['idempotency-key' => 'order-4711']);

        expect($http->lastRequest()?->idempotencyKey())->toBe('order-4711');
    });

    it('validates a caller-supplied key against the server rule', function (): void {
        [$client] = fakeClient();

        expect(fn() => $client->request('POST', 'tags', json: [], headers: ['Idempotency-Key' => 'has space']))
            ->toThrow(InvalidArgumentException::class, 'visible ASCII');
    });

    it('sends no key when idempotency is switched off', function (): void {
        [$client, $http] = fakeClient([MockResponse::json([])], new ClientOptions(idempotency: false));

        $client->request('POST', 'tags', json: ['key' => 'a']);

        expect($http->lastRequest()?->hasHeader('Idempotency-Key'))->toBeFalse();
    });

    it('can be disabled for a single request', function (): void {
        [$client, $http] = fakeClient([MockResponse::json([])]);

        $client->send(Request::post('tags', ['key' => 'a'])->withoutIdempotencyKey());

        expect($http->lastRequest()?->hasHeader('Idempotency-Key'))->toBeFalse();
    });
});

describe('request building', function (): void {
    it('joins the base URI, the path and the encoded query', function (): void {
        [$client, $http] = fakeClient([MockResponse::json([])]);

        $client->request('GET', '/tags', ['label' => 'kunde:4711', 'per_page' => 1]);

        expect($http->lastRequest()?->uri)->toBe('https://api.livck.cloud/v1/tags?label=kunde%3A4711&per_page=1');
    });

    it('sends multipart bodies with a boundary', function (): void {
        [$client, $http] = fakeClient([MockResponse::json([])]);

        $client->send(Request::post('statuspages/abc/assets/logo')->withMultipart(
            MultipartPart::contents('file', 'PNGDATA', 'logo.png', 'image/png'),
        ));

        $sent = $http->lastRequest();

        expect($sent?->header('Content-Type'))->toStartWith('multipart/form-data; boundary=')
            ->and($sent?->body)->toContain('name="file"; filename="logo.png"')
            ->and($sent?->body)->toContain('Content-Type: image/png')
            ->and($sent?->body)->toContain('PNGDATA');
    });

    it('treats a redirect as an unexpected response instead of following it', function (): void {
        [$client] = singleShotClient([MockResponse::raw('', 302, ['Location' => 'https://elsewhere.test'])]);

        expect(fn() => $client->request('GET', 'tags'))
            ->toThrow(UnexpectedResponseException::class, 'redirect');
    });

    it('returns the response with status, headers and decoded body', function (): void {
        [$client] = fakeClient([MockResponse::json(['type' => 'user'], 200, ['X-RateLimit-Limit' => '120', 'X-RateLimit-Remaining' => '119'])]);

        $response = $client->request('GET', 'me');

        expect($response->status())->toBe(200)
            ->and($response->json())->toBe(['type' => 'user'])
            ->and($response->rateLimit()?->limit)->toBe(120)
            ->and($response->rateLimit()?->remaining)->toBe(119)
            ->and($response->wasReplayed())->toBeFalse()
            ->and($response->requestMethod())->toBe('GET')
            ->and($response->requestUri())->toBe('https://api.livck.cloud/v1/me');
    });

    it('reports a replayed response', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => []], 201)->replayed()]);

        expect($client->request('POST', 'tags', json: ['key' => 'a'])->wasReplayed())->toBeTrue();
    });
});

describe('immutability', function (): void {
    it('returns new instances from with…() and keeps the original as it was', function (): void {
        [$client, $http] = fakeClient([MockResponse::json([]), MockResponse::json([])]);

        $localized = $client->withLocale('de');
        $reconfigured = $client->withOptions(new ClientOptions(userAgentSuffix: 'x/1'));
        $other = $client->withHttpClient(new FakeHttpClient([MockResponse::json([])]));

        expect($localized)->not->toBe($client)
            ->and($reconfigured)->not->toBe($client)
            ->and($other)->not->toBe($client)
            ->and($client->options()->locale)->toBeNull()
            ->and($localized->options()->locale)->toBe('de')
            ->and($reconfigured->options()->userAgentSuffix)->toBe('x/1')
            ->and($client)->toBeInstanceOf(CloudClientInterface::class);

        $localized->request('GET', 'tags');
        $client->request('GET', 'tags');

        expect($http->recorded()[0]->header('Accept-Language'))->toBe('de')
            ->and($http->recorded()[1]->hasHeader('Accept-Language'))->toBeFalse();
    });

    it('keeps one resource instance per client', function (): void {
        [$client] = fakeClient();

        expect($client->tags())->toBe($client->tags())
            ->and($client->withLocale('en')->tags())->not->toBe($client->tags())
            ->and($client->enrollmentKeys())->toBe($client->enrollmentKeys())
            ->and($client->withLocale('en')->enrollmentKeys())->not->toBe($client->enrollmentKeys());
    });

    it('accepts a BearerToken instance as well as a string', function (): void {
        $http = new FakeHttpClient([MockResponse::json([])]);
        $client = new CloudClient(new BearerToken('lvk_abc'), httpClient: $http);

        $client->request('GET', 'me');

        expect($http->lastRequest()?->header('Authorization'))->toBe('Bearer lvk_abc');
    });
});

describe('token redaction', function (): void {
    it('keeps the token out of var_dump, print_r and var_export', function (): void {
        $client = new CloudClient('lvk_supersecret_value', httpClient: new FakeHttpClient());

        ob_start();
        var_dump($client);
        $dumped = (string) ob_get_clean();

        expect($dumped)->not->toContain('lvk_supersecret_value')
            ->and($dumped)->toContain('[redacted]')
            ->and(print_r($client, true))->not->toContain('lvk_supersecret_value')
            ->and(var_export($client, true))->not->toContain('lvk_supersecret_value')
            ->and(json_encode($client))->not->toContain('lvk_supersecret_value');
    });
});

describe('fake()', function (): void {
    it('hands back the client and the fake it talks to, without sleeping on retries', function (): void {
        [$client, $http] = CloudClient::fake([
            MockResponse::error('Too many requests.', 429)->withRetryAfter(3),
            MockResponse::json(['ok' => true]),
        ]);

        $response = $client->request('GET', 'tags');

        expect($response->json())->toBe(['ok' => true])
            ->and($http->recorded())->toHaveCount(2)
            ->and($http->delays())->toBe([3.0]);
    });
});
