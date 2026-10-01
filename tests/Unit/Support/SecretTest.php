<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Secret;

it('hands out the value on request only', function (): void {
    expect((new Secret('lve_supersecret'))->reveal())->toBe('lve_supersecret');
});

it('refuses an empty value', function (): void {
    expect(fn(): Secret => new Secret(''))->toThrow(InvalidArgumentException::class, 'must not be empty');
});

it('hides the value from every dump and from JSON', function (): void {
    $secret = new Secret('lve_supersecret');

    ob_start();
    var_dump($secret);
    $dumped = (string) ob_get_clean();

    expect($dumped)->not->toContain('supersecret')
        ->and($dumped)->toContain('[redacted]')
        ->and(print_r($secret, true))->not->toContain('supersecret')
        ->and(var_export($secret, true))->not->toContain('supersecret')
        ->and((string) json_encode($secret))->toBe('{}')
        ->and((string) json_encode(['key' => $secret]))->not->toContain('supersecret');
});

it('refuses to be serialized', function (): void {
    expect(fn(): string => serialize(new Secret('lve_supersecret')))
        ->toThrow(LogicException::class, 'must not be serialized');
});

it('cannot be turned into a string by accident', function (): void {
    expect(new Secret('lve_supersecret'))->not->toBeInstanceOf(Stringable::class);
});
