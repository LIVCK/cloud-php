<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Http\BearerToken;

it('builds the Authorization header and trims surrounding whitespace', function (): void {
    expect((new BearerToken("  lvk_abc123\n"))->authorizationHeader())->toBe('Bearer lvk_abc123');
});

it('rejects an empty token or one with inner whitespace', function (string $token): void {
    expect(fn(): BearerToken => new BearerToken($token))->toThrow(InvalidArgumentException::class);
})->with(['', '   ', 'lvk_a b', "lvk_a\tb", 'lvk_ü']);

it('hides the value from every dump', function (): void {
    $token = new BearerToken('lvk_supersecret');

    ob_start();
    var_dump($token);
    $dumped = (string) ob_get_clean();

    expect($dumped)->not->toContain('supersecret')
        ->and($dumped)->toContain('[redacted]')
        ->and(print_r($token, true))->not->toContain('supersecret')
        ->and(var_export($token, true))->not->toContain('supersecret');
});

it('refuses to be serialized', function (): void {
    expect(fn(): string => serialize(new BearerToken('lvk_supersecret')))
        ->toThrow(LogicException::class, 'must not be serialized');
});

it('scrubs the value from arbitrary text', function (): void {
    $token = new BearerToken('lvk_supersecret');

    expect($token->redact('cURL error for Bearer lvk_supersecret at host'))
        ->toBe('cURL error for Bearer [redacted] at host');
});

it('keeps the value out of what Symfony VarDumper prints', function (): void {
    $closure = (new ReflectionProperty(BearerToken::class, 'value'))->getValue(new BearerToken('lc_supersecret'));

    if (! $closure instanceof Closure) {
        throw new LogicException('The value is expected inside a closure.');
    }

    $captured = (new ReflectionFunction($closure))->getStaticVariables();

    // VarDumper prints a closure's captured strings and numbers, and cuts every object.
    expect(array_filter($captured, static fn(mixed $variable): bool => ! is_object($variable)))->toBe([]);
});
