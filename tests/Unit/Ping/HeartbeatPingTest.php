<?php

declare(strict_types=1);

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Ping\HeartbeatPing;
use LIVCK\Cloud\Ping\PingDetails;
use LIVCK\Cloud\Ping\PingResult;
use LIVCK\Cloud\Support\Secret;
use LIVCK\Cloud\Testing\FakeHttpClient;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Tests\Support\RecordingLogger;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

describe('signals', function (): void {
    it('sends each signal to its path, as a POST without a body', function (string $signal, string $path): void {
        [$ping, $http] = fakePing([MockResponse::raw('OK')]);

        $result = match ($signal) {
            'start' => $ping->start(),
            'success' => $ping->success(),
            'fail' => $ping->fail(),
            'exit 0' => $ping->exitCode(0),
            'exit 3' => $ping->exitCode(3),
            'exit 255' => $ping->exitCode(255),
            'exit -1' => $ping->exitCode(-1),
            default => throw new LogicException($signal),
        };

        $sent = $http->lastRequest();

        expect($result->ok())->toBeTrue()
            ->and($result->sent)->toBeTrue()
            ->and($result->status)->toBe(200)
            ->and($result->error)->toBeNull()
            ->and($sent?->method)->toBe('POST')
            ->and($sent?->uri)->toBe(pingUrl() . $path)
            ->and($sent?->body)->toBe('')
            ->and($sent?->hasHeader('Content-Type'))->toBeFalse();
    })->with([
        'start' => ['start', '/start'],
        'success' => ['success', ''],
        'fail' => ['fail', '/fail'],
        'exit code 0' => ['exit 0', '/0'],
        'exit code 3' => ['exit 3', '/3'],
        'exit code 255' => ['exit 255', '/255'],
        'negative exit code' => ['exit -1', '/fail'],
    ]);

    it('never sends an Authorization header, also when reached through the API client', function (): void {
        [$client, $http] = CloudClient::fake([MockResponse::raw('OK'), MockResponse::raw('OK')]);

        $client->heartbeatPing(pingUrl())->start();
        (new HeartbeatPing(pingUrl(), httpClient: $http))->success();

        foreach ($http->recorded() as $request) {
            expect($request->hasHeader('Authorization'))->toBeFalse()
                ->and($request->hasHeader('Idempotency-Key'))->toBeFalse()
                ->and($request->uri)->toStartWith(pingUrl())
                ->and($request->header('User-Agent'))->toBe(sprintf('livck-cloud-php/%s PHP/%s', CloudClient::VERSION, PHP_VERSION));
        }

        $http->assertSentCount(2);
    });

    it('uses the user agent suffix and the logger of the API client', function (): void {
        $logger = new RecordingLogger();
        $http = new FakeHttpClient([MockResponse::raw('OK')]);
        $client = new CloudClient('lvk_test_token', new ClientOptions(userAgentSuffix: 'panel/2.3', logger: $logger), $http);

        $client->heartbeatPing(pingUrl())->success();

        expect($http->lastRequest()?->header('User-Agent'))->toEndWith(' panel/2.3')
            ->and($logger->records)->toHaveCount(1)
            ->and($logger->records[0]['level'])->toBe('debug')
            ->and($logger->records[0]['context'])->toHaveKeys(['signal', 'status', 'duration_ms'])
            ->and($logger->records[0]['context']['signal'] ?? null)->toBe('success')
            ->and($logger->records[0]['context']['status'] ?? null)->toBe(200);
    });

    it('accepts the URL as a secret and with a trailing slash', function (): void {
        [$ping, $http] = fakePing([MockResponse::raw('OK'), MockResponse::raw('OK')], new Secret(pingUrl() . '/'));

        $ping->fail();
        (new HeartbeatPing(' ' . pingUrl() . '/ ', httpClient: $http))->start();

        expect($http->recorded()[0]->uri)->toBe(pingUrl() . '/fail')
            ->and($http->recorded()[1]->uri)->toBe(pingUrl() . '/start');
    });

    it('accepts plain http on a loopback host, for a development stack', function (): void {
        [$ping, $http] = fakePing([MockResponse::raw('OK')], 'http://localhost/ping/' . pingToken());

        expect($ping->success()->ok())->toBeTrue()
            ->and($http->lastRequest()?->uri)->toBe('http://localhost/ping/' . pingToken());
    });
});

describe('body', function (): void {
    it('sends metrics and the log as JSON', function (): void {
        [$ping, $http] = fakePing([MockResponse::raw('OK')]);

        $result = $ping->success(PingDetails::make()->withDuration(12.5)->withMetric('rows', 1520)->withLog("done\n"));

        $sent = $http->lastRequest();

        expect($result->ok())->toBeTrue()
            ->and($sent?->header('Content-Type'))->toBe('application/json')
            ->and($sent?->body)->toBe('{"metrics":{"duration":12.5,"rows":1520},"metadata":"done\n"}');
    });

    it('sends no body for empty details', function (): void {
        [$ping, $http] = fakePing([MockResponse::raw('OK')]);

        $ping->fail(PingDetails::make()->withLog("\x00\x01"));

        expect($http->lastRequest()?->body)->toBe('')
            ->and($http->lastRequest()?->hasHeader('Content-Type'))->toBeFalse();
    });

    it('reports figures that were left out', function (): void {
        [$ping] = fakePing([MockResponse::raw('OK')]);

        $result = $ping->success(PingDetails::make()->withMetric('Rows', 1)->withMetric('ok', 2));

        expect($result->ok())->toBeTrue()
            ->and($result->droppedMetrics)->toBe(['Rows']);
    });

    it('keeps the body within the limit LIVCK reads', function (): void {
        [$ping, $http] = fakePing([MockResponse::raw('OK')]);

        $ping->fail(PingDetails::make()->withLog(str_repeat("\"\n", 5000)));

        expect(strlen($http->lastRequest()->body ?? ''))->toBeLessThanOrEqual(8192);
    });
});

describe('failures never throw', function (): void {
    it('maps a status to the result', function (int $status, bool $unknownUrl, string $error): void {
        [$ping, $http] = fakePing([MockResponse::raw('nope', $status, ['Retry-After' => '30'])]);

        $result = $ping->start();

        expect($result->sent)->toBeTrue()
            ->and($result->noResponse)->toBeFalse()
            ->and($result->ok())->toBeFalse()
            ->and($result->status)->toBe($status)
            ->and($result->unknownUrl)->toBe($unknownUrl)
            ->and($result->error)->toContain($error);

        $http->assertSentCount(1);
        expect($http->delays())->toBe([]);
    })->with([
        '404' => [404, true, 'does not know this ping URL'],
        '429' => [429, false, 'HTTP 429'],
        '500' => [500, false, 'HTTP 500'],
        '503' => [503, false, 'HTTP 503'],
        '301' => [301, false, 'redirect'],
        '400' => [400, false, 'HTTP 400'],
    ]);

    it('turns a network error into a result, once, without the URL', function (): void {
        $logger = new RecordingLogger();
        [$ping, $http] = fakePing([
            MockResponse::networkError('cURL error 28: Operation timed out after 3001 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for ' . pingUrl() . '/start'),
        ], logger: $logger);

        $result = $ping->start();

        expect($result->sent)->toBeFalse()
            ->and($result->noResponse)->toBeTrue()
            ->and($result->status)->toBeNull()
            ->and($result->unknownUrl)->toBeFalse()
            ->and($result->error)->toContain('Operation timed out')
            ->and($result->error)->toContain('[redacted]')
            ->and($logger->records)->toHaveCount(1)
            ->and($logger->records[0]['level'])->toBe('warning')
            ->and(pingTrace($ping, $result, $logger))->not->toContain(pingToken());

        $http->assertSentCount(1);
    });

    it('redacts the token wherever the message quotes it', function (): void {
        [$ping] = fakePing([MockResponse::networkError('Could not resolve host for /ping/' . pingToken() . '?x=' . rawurlencode(pingUrl()))]);

        expect($ping->success()->error)->not->toContain(pingToken());
    });

    it('answers with a result for an unusable URL and sends nothing', function (string $url, string $error): void {
        $logger = new RecordingLogger();
        [$ping, $http] = fakePing([], $url, $logger);

        $result = $ping->success();

        expect($result->sent)->toBeFalse()
            ->and($result->noResponse)->toBeFalse()
            ->and($result->error)->toContain($error)
            ->and(pingTrace($ping, $result, $logger))->not->toContain('hb_secretpart');

        $http->assertNothingSent();
    })->with([
        'empty' => ['', 'empty'],
        'blank' => ['   ', 'empty'],
        'no scheme' => ['ping.livck.cloud/hb_secretpart', 'absolute URL'],
        'no path' => ['https://ping.livck.cloud', 'absolute URL'],
        'plain http' => ['http://ping.livck.cloud/hb_secretpart', 'https'],
        'query' => ['https://ping.livck.cloud/hb_secretpart?x=1', 'query string'],
        'credentials' => ['https://user:hb_secretpart@ping.livck.cloud/x', 'credentials'],
    ]);

    it('answers with a result when the HTTP client throws something other than a network error', function (): void {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('broken middleware for ' . $request->getUri());
            }
        };

        $result = (new HeartbeatPing(pingUrl(), httpClient: $client))->fail();

        expect($result->sent)->toBeFalse()
            ->and($result->noResponse)->toBeFalse()
            ->and($result->error)->toBe('No response: broken middleware for [redacted]/fail');
    });

    it('does not throw against a real port that refuses the connection', function (): void {
        $result = (new HeartbeatPing('http://127.0.0.1:1/' . pingToken(), timeout: 1.0))->success();

        expect($result->sent)->toBeFalse()
            ->and($result->noResponse)->toBeTrue()
            ->and($result->error)->not->toBeNull()
            ->and($result->error)->not->toContain(pingToken());
    });

    it('gives up after the timeout against a server that never answers', function (): void {
        $server = stream_socket_server('tcp://127.0.0.1:0');

        if ($server === false) {
            throw new LogicException('No local socket for the test.');
        }

        $address = (string) stream_socket_get_name($server, false);
        $started = microtime(true);

        $result = (new HeartbeatPing('http://' . $address . '/' . pingToken(), timeout: 0.3))->start();

        $elapsed = microtime(true) - $started;
        fclose($server);

        expect($result->sent)->toBeFalse()
            ->and($result->noResponse)->toBeTrue()
            ->and($result->error)->toContain('timed out')
            ->and($result->error)->not->toContain(pingToken())
            ->and($elapsed)->toBeLessThan(2.0);
    });
});

describe('configuration', function (): void {
    it('builds its own client with a three second timeout and no redirects', function (): void {
        $ping = new HeartbeatPing(pingUrl());
        $client = (new ReflectionProperty(HeartbeatPing::class, 'httpClient'))->getValue($ping);

        if (! $client instanceof GuzzleHttp\Client) {
            throw new LogicException('Guzzle is expected in the dev dependencies.');
        }

        expect($ping->timeout())->toBe(3.0)
            ->and((new ReflectionProperty(GuzzleHttp\Client::class, 'config'))->getValue($client))
            ->toMatchArray(['timeout' => 3.0, 'connect_timeout' => 3.0, 'allow_redirects' => false, 'http_errors' => false]);
    });

    it('builds its own short client through the API client when the SDK built that one', function (): void {
        $ping = (new CloudClient('lvk_test_token'))->heartbeatPing(pingUrl(), timeout: 1.5);
        $client = (new ReflectionProperty(HeartbeatPing::class, 'httpClient'))->getValue($ping);

        if (! $client instanceof GuzzleHttp\Client) {
            throw new LogicException('Guzzle is expected in the dev dependencies.');
        }

        expect((new ReflectionProperty(GuzzleHttp\Client::class, 'config'))->getValue($client))->toMatchArray(['timeout' => 1.5, 'connect_timeout' => 1.5]);
    });

    it('refuses a timeout that cannot work', function (float $timeout): void {
        expect(fn(): HeartbeatPing => new HeartbeatPing(pingUrl(), $timeout))->toThrow(InvalidArgumentException::class, 'timeout');
    })->with(['zero' => [0.0], 'negative' => [-1.0], 'infinite' => [INF], 'nan' => [NAN]]);

    it('refuses a user agent suffix that is not a header value', function (): void {
        expect(fn(): HeartbeatPing => new HeartbeatPing(pingUrl(), userAgentSuffix: "a\r\nb"))->toThrow(InvalidArgumentException::class, 'userAgentSuffix');
    });

    it('keeps the URL out of dumps and refuses to be serialized', function (): void {
        $ping = new HeartbeatPing(pingUrl(), httpClient: new FakeHttpClient());

        expect(pingTrace($ping, PingResult::notSent('x'), new RecordingLogger()))->not->toContain(pingToken())
            ->and(var_export($ping, true))->not->toContain(pingToken())
            ->and(fn(): string => serialize($ping))->toThrow(LogicException::class, 'must not be serialized');
    });

    it('builds a new ping on every call of the API client', function (): void {
        [$client] = CloudClient::fake();

        expect($client->heartbeatPing(pingUrl()))->not->toBe($client->heartbeatPing(pingUrl()));
    });
});
