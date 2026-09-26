<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Enums\HttpAuthType;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Json;
use LIVCK\Cloud\Support\KeepSecret;

it('writes the catalog\'s auth shape per type', function (): void {
    expect(HttpAuth::none()->toArray())->toBe(['type' => 'none'])
        ->and(HttpAuth::none()->type())->toBe(HttpAuthType::None)
        ->and(HttpAuth::bearer('t0k')->toArray())->toBe(['type' => 'bearer', 'token' => 't0k'])
        ->and(HttpAuth::basic('monitor', 'pw')->toArray())->toBe(['type' => 'basic', 'username' => 'monitor', 'password' => 'pw'])
        ->and(HttpAuth::apiKey('X-Api-Key', 'k')->toArray())->toBe(['type' => 'api_key', 'header' => 'X-Api-Key', 'value' => 'k'])
        ->and(HttpAuth::apiKey('X-Api-Key', 'k')->type())->toBe(HttpAuthType::ApiKey);
});

it('keeps a stored secret with the sentinel', function (): void {
    expect(Json::encode(HttpAuth::bearer(KeepSecret::keep())->toArray()))->toBe('{"type":"bearer","token":"__LIVCK_KEEP_UNCHANGED__"}')
        ->and(Json::encode(HttpAuth::basic('monitor', KeepSecret::keep())->toArray()))->toBe('{"type":"basic","username":"monitor","password":"__LIVCK_KEEP_UNCHANGED__"}')
        ->and(Json::encode(HttpAuth::apiKey('X-Api-Key', KeepSecret::keep())->toArray()))->toBe('{"type":"api_key","header":"X-Api-Key","value":"__LIVCK_KEEP_UNCHANGED__"}');
});

it('refuses blanks', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty token' => [fn(): HttpAuth => HttpAuth::bearer(''), 'token must not be empty'],
    'blank username' => [fn(): HttpAuth => HttpAuth::basic(' ', 'pw'), 'username must not be blank'],
    'empty password' => [fn(): HttpAuth => HttpAuth::basic('monitor', ''), 'password must not be empty'],
    'blank header' => [fn(): HttpAuth => HttpAuth::apiKey('', 'k'), 'header name must not be blank'],
    'empty key' => [fn(): HttpAuth => HttpAuth::apiKey('X-Api-Key', ''), 'value must not be empty'],
]);
