<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Translatable;

it('sends a plain text as a string', function (): void {
    $text = Translatable::of('Störung im Rechenzentrum');

    expect($text->isMultilingual())->toBeFalse()
        ->and($text->text())->toBe('Störung im Rechenzentrum')
        ->and($text->byLocale())->toBe([])
        ->and(json_encode($text, JSON_UNESCAPED_UNICODE))->toBe('"Störung im Rechenzentrum"');
});

it('sends translations as a locale map', function (): void {
    $text = Translatable::translations(['de' => 'Störung', 'en' => 'Outage']);

    expect($text->isMultilingual())->toBeTrue()
        ->and($text->text('en'))->toBe('Outage')
        ->and($text->text())->toBe('Störung')
        ->and($text->text('fr'))->toBeNull()
        ->and($text->byLocale())->toBe(['de' => 'Störung', 'en' => 'Outage'])
        ->and(json_encode($text, JSON_UNESCAPED_UNICODE))->toBe('{"de":"Störung","en":"Outage"}');
});

it('accepts a string, a map or an instance through from()', function (): void {
    $instance = Translatable::of('x');

    expect(Translatable::from('x')->text())->toBe('x')
        ->and(Translatable::from(['pt-BR' => 'x'])->text('pt-BR'))->toBe('x')
        ->and(Translatable::from($instance))->toBe($instance);
});

it('rejects blank texts, empty maps and malformed locales', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank text' => [fn(): Translatable => Translatable::of('  '), 'blank'],
    'empty map' => [fn(): Translatable => Translatable::translations([]), 'at least one'],
    'blank translation' => [fn(): Translatable => Translatable::translations(['de' => '']), 'blank'],
    'bad locale' => [fn(): Translatable => Translatable::translations(['deutsch!' => 'x']), 'locale code'],
]);
