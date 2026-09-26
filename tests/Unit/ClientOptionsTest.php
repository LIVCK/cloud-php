<?php

declare(strict_types=1);

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use Psr\Log\NullLogger;

describe('defaults', function (): void {
    it('targets the production API with conservative timeouts and two retries', function (): void {
        $options = new ClientOptions();

        expect($options->baseUri)->toBe('https://api.livck.cloud/v1')
            ->and($options->locale)->toBeNull()
            ->and($options->timeout)->toBe(30.0)
            ->and($options->connectTimeout)->toBe(10.0)
            ->and($options->maxRetries)->toBe(2)
            ->and($options->backoffBase)->toBe(0.5)
            ->and($options->backoffCap)->toBe(8.0)
            ->and($options->maxRetryAfter)->toBe(60)
            ->and($options->idempotency)->toBeTrue()
            ->and($options->userAgentSuffix)->toBeNull()
            ->and($options->logger)->toBeInstanceOf(NullLogger::class);
    });
});

describe('base URI', function (): void {
    it('accepts https anywhere and trims a trailing slash', function (): void {
        expect((new ClientOptions('https://api.example.test/v1/'))->baseUri)->toBe('https://api.example.test/v1');
    });

    it('accepts plain http on loopback hosts only', function (string $uri): void {
        expect((new ClientOptions($uri))->baseUri)->toBe(rtrim($uri, '/'));
    })->with([
        'localhost' => 'http://localhost:8000/api/v1',
        '*.localhost' => 'http://app.localhost/api/v1',
        '127.0.0.1' => 'http://127.0.0.1:8000/api/v1',
        '127.0.0.0/8' => 'http://127.42.0.1/api/v1',
        '::1' => 'http://[::1]:8000/api/v1',
    ]);

    it('refuses plain http on any other host so the token never travels in the clear', function (string $uri): void {
        expect(fn(): ClientOptions => new ClientOptions($uri))
            ->toThrow(InvalidArgumentException::class, 'must use https');
    })->with([
        'public host' => 'http://api.livck.cloud/v1',
        'private network' => 'http://10.0.0.5/api/v1',
        'localhost lookalike' => 'http://localhost.example.com/api/v1',
        'other loopback-ish ip' => 'http://128.0.0.1/api/v1',
    ]);

    it('refuses URLs that are not absolute, or carry a query, fragment or credentials', function (string $uri, string $message): void {
        expect(fn(): ClientOptions => new ClientOptions($uri))
            ->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'relative' => ['/v1', 'absolute URL'],
        'no scheme' => ['api.livck.cloud/v1', 'absolute URL'],
        'ftp' => ['ftp://api.livck.cloud/v1', 'must use https'],
        'query' => ['https://api.livck.cloud/v1?x=1', 'query string'],
        'fragment' => ['https://api.livck.cloud/v1#top', 'fragment'],
        'credentials' => ['https://user:secret@api.livck.cloud/v1', 'credentials'],
    ]);
});

describe('validation', function (): void {
    it('rejects values outside their range', function (Closure $build, string $message): void {
        expect($build)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'zero timeout' => [fn(): ClientOptions => new ClientOptions(timeout: 0.0), 'timeout'],
        'negative connect timeout' => [fn(): ClientOptions => new ClientOptions(connectTimeout: -1.0), 'timeout'],
        'negative retries' => [fn(): ClientOptions => new ClientOptions(maxRetries: -1), 'maxRetries'],
        'too many retries' => [fn(): ClientOptions => new ClientOptions(maxRetries: 11), 'maxRetries'],
        'zero backoff base' => [fn(): ClientOptions => new ClientOptions(backoffBase: 0.0), 'backoffBase'],
        'cap below base' => [fn(): ClientOptions => new ClientOptions(backoffBase: 2.0, backoffCap: 1.0), 'backoffCap'],
        'negative max retry-after' => [fn(): ClientOptions => new ClientOptions(maxRetryAfter: -1), 'maxRetryAfter'],
        'blank locale' => [fn(): ClientOptions => new ClientOptions(locale: ''), 'locale'],
        'locale with line break' => [fn(): ClientOptions => new ClientOptions(locale: "de\r\nX-Injected: 1"), 'locale'],
        'suffix with line break' => [fn(): ClientOptions => new ClientOptions(userAgentSuffix: "panel\n"), 'userAgentSuffix'],
    ]);
});

describe('immutability', function (): void {
    it('returns modified copies and leaves the original untouched', function (): void {
        $original = new ClientOptions();

        $modified = $original
            ->withBaseUri('http://localhost:8000/api/v1')
            ->withLocale('de')
            ->withTimeout(5.0, 2.0)
            ->withMaxRetries(0)
            ->withBackoff(1.0, 4.0)
            ->withMaxRetryAfter(5)
            ->withIdempotency(false)
            ->withUserAgentSuffix('panel/1.0');

        expect($modified)->not->toBe($original)
            ->and($modified->baseUri)->toBe('http://localhost:8000/api/v1')
            ->and($modified->locale)->toBe('de')
            ->and($modified->timeout)->toBe(5.0)
            ->and($modified->connectTimeout)->toBe(2.0)
            ->and($modified->maxRetries)->toBe(0)
            ->and($modified->backoffBase)->toBe(1.0)
            ->and($modified->backoffCap)->toBe(4.0)
            ->and($modified->maxRetryAfter)->toBe(5)
            ->and($modified->idempotency)->toBeFalse()
            ->and($modified->userAgentSuffix)->toBe('panel/1.0')
            ->and($original->baseUri)->toBe('https://api.livck.cloud/v1')
            ->and($original->locale)->toBeNull()
            ->and($original->idempotency)->toBeTrue();
    });

    it('validates on every copy', function (): void {
        expect(fn(): ClientOptions => (new ClientOptions())->withBaseUri('http://api.livck.cloud/v1'))
            ->toThrow(InvalidArgumentException::class);
    });
});
