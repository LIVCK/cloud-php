<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Http\MultipartPart;
use LIVCK\Cloud\Http\Request;

describe('construction', function (): void {
    it('normalises the method and strips a leading slash from the path', function (): void {
        $request = Request::make('get', '/tags');

        expect($request->method)->toBe('GET')
            ->and($request->path)->toBe('tags')
            ->and($request->query)->toBe([])
            ->and($request->json)->toBeNull()
            ->and($request->multipart)->toBe([])
            ->and($request->headers)->toBe([])
            ->and($request->idempotencyKey)->toBeNull()
            ->and($request->autoIdempotencyKey)->toBeTrue();
    });

    it('offers a factory per method', function (): void {
        expect(Request::get('tags', ['key' => 'k'])->method)->toBe('GET')
            ->and(Request::head('tags')->method)->toBe('HEAD')
            ->and(Request::post('tags', ['key' => 'k'])->json)->toBe(['key' => 'k'])
            ->and(Request::put('tags/a', ['key' => 'k'])->method)->toBe('PUT')
            ->and(Request::patch('tags/a', ['key' => 'k'])->method)->toBe('PATCH')
            ->and(Request::delete('tags/a')->method)->toBe('DELETE');
    });

    it('rejects unsupported methods and malformed paths', function (Closure $build, string $message): void {
        expect($build)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'method' => [fn(): Request => Request::make('TRACE', 'tags'), 'not a supported HTTP method'],
        'empty path' => [fn(): Request => Request::make('GET', '/'), 'must not be empty'],
        'absolute url' => [fn(): Request => Request::make('GET', 'https://api.livck.cloud/v1/tags'), 'relative to the base URI'],
        'protocol-relative' => [fn(): Request => Request::make('GET', '//host/tags'), 'relative to the base URI'],
        'query in path' => [fn(): Request => Request::make('GET', 'tags?key=a'), 'query string'],
        'fragment in path' => [fn(): Request => Request::make('GET', 'tags#x'), 'query string'],
        'whitespace' => [fn(): Request => Request::make('GET', "tags\n"), 'whitespace'],
    ]);
});

describe('headers', function (): void {
    it('merges headers case-insensitively, the later value winning', function (): void {
        $request = Request::get('tags')->withHeader('X-Trace', 'a')->withHeaders(['x-trace' => 'b', 'Accept' => 'text/plain']);

        expect($request->headers)->toBe(['x-trace' => 'b', 'Accept' => 'text/plain']);
    });

    it('refuses managed headers, invalid names and line breaks', function (string $name, string $value, string $message): void {
        expect(fn(): Request => Request::get('tags')->withHeader($name, $value))
            ->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'authorization' => ['Authorization', 'Bearer x', 'managed by the client'],
        'user agent' => ['user-agent', 'x', 'managed by the client'],
        'content type' => ['Content-Type', 'text/plain', 'managed by the client'],
        'idempotency key' => ['Idempotency-Key', 'k', 'withIdempotencyKey()'],
        'invalid name' => ['X Header', 'v', 'not a valid header name'],
        'crlf' => ['X-Trace', "a\r\nX-Other: b", 'line breaks'],
    ]);
});

describe('idempotency key', function (): void {
    it('accepts a well-formed key on POST and turns automatic generation off', function (): void {
        $request = Request::post('tags', ['key' => 'k'])->withIdempotencyKey('order-4711');

        expect($request->idempotencyKey)->toBe('order-4711')
            ->and($request->autoIdempotencyKey)->toBeFalse();
    });

    it('takes the key as the third argument of the POST factory, null keeping the automatic one', function (): void {
        $keyed = Request::post('tags', ['key' => 'k'], 'order-4711');
        $automatic = Request::post('tags', ['key' => 'k'], null);

        expect($keyed->idempotencyKey)->toBe('order-4711')
            ->and($keyed->autoIdempotencyKey)->toBeFalse()
            ->and($automatic->idempotencyKey)->toBeNull()
            ->and($automatic->autoIdempotencyKey)->toBeTrue();

        expect(fn(): Request => Request::post('tags', null, 'has space'))->toThrow(InvalidArgumentException::class, 'visible ASCII');
    });

    it('rejects keys that are not 1 to 255 visible ASCII characters', function (string $key): void {
        expect(fn(): Request => Request::post('tags')->withIdempotencyKey($key))
            ->toThrow(InvalidArgumentException::class, 'visible ASCII');
    })->with(['', 'with space', "tab\tkey", 'ü', str_repeat('k', 256)]);

    it('rejects a key on anything but POST', function (): void {
        expect(fn(): Request => Request::put('tags/a')->withIdempotencyKey('k'))
            ->toThrow(InvalidArgumentException::class, 'POST requests only');
    });

    it('can be switched off for one request', function (): void {
        $request = Request::post('tags')->withIdempotencyKey('k')->withoutIdempotencyKey();

        expect($request->idempotencyKey)->toBeNull()
            ->and($request->autoIdempotencyKey)->toBeFalse();
    });
});

describe('bodies', function (): void {
    it('carries either JSON or multipart parts, never both', function (): void {
        $part = MultipartPart::text('name', 'value');

        expect(fn(): Request => Request::post('x', ['a' => 1])->withMultipart($part))
            ->toThrow(InvalidArgumentException::class, 'not both');

        expect(fn(): Request => Request::post('x')->withMultipart($part)->withJson(['a' => 1]))
            ->toThrow(InvalidArgumentException::class, 'not both');
    });

    it('allows multipart on POST only', function (): void {
        expect(fn(): Request => Request::put('x')->withMultipart(MultipartPart::text('a', 'b')))
            ->toThrow(InvalidArgumentException::class, 'POST only');
    });

    it('reports whether a body is present', function (): void {
        expect(Request::get('x')->hasBody())->toBeFalse()
            ->and(Request::post('x', [])->hasBody())->toBeTrue()
            ->and(Request::post('x')->withMultipart(MultipartPart::text('a', 'b'))->isMultipart())->toBeTrue();
    });
});

it('is immutable', function (): void {
    $original = Request::get('tags');
    $modified = $original->withQuery(['key' => 'k'])->withHeader('X-A', '1');

    expect($original->query)->toBe([])
        ->and($original->headers)->toBe([])
        ->and($modified->query)->toBe(['key' => 'k'])
        ->and($modified->target())->toBe('tags?key=k');
});

it('rejects query parameters without a name', function (): void {
    expect(fn(): Request => Request::get('tags', ['' => 'x']))->toThrow(InvalidArgumentException::class, 'non-empty strings');
});
