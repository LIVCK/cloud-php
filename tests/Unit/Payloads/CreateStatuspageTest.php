<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Payloads\CreateStatuspage;
use LIVCK\Cloud\Support\Translatable;

it('sends the name and only a slug that was given', function (): void {
    expect(CreateStatuspage::make('Acme Hosting')->toArray())->toBe(['name' => 'Acme Hosting'])
        ->and(CreateStatuspage::make('Acme Hosting')->withSlug('acme')->toArray())->toBe(['name' => 'Acme Hosting', 'slug' => 'acme'])
        ->and((new CreateStatuspage('Acme', slug: 'acme'))->toArray())->toBe(['name' => 'Acme', 'slug' => 'acme']);
});

it('sends a name in several languages as a locale map', function (): void {
    $create = CreateStatuspage::make(Translatable::translations(['de' => 'Acme Status', 'en' => 'Acme Status EN']));

    expect($create->toArray())->toBe(['name' => ['de' => 'Acme Status', 'en' => 'Acme Status EN']])
        ->and($create->name->isMultilingual())->toBeTrue();
});

it('is immutable', function (): void {
    $base = CreateStatuspage::make('Acme');
    $withSlug = $base->withSlug('acme');

    expect($base->slug)->toBeNull()
        ->and($withSlug->slug)->toBe('acme')
        ->and($withSlug->name)->toBe($base->name);
});

it('refuses a blank name and a blank slug', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank name' => [fn(): CreateStatuspage => CreateStatuspage::make(' '), 'must not be blank'],
    'blank slug' => [fn(): CreateStatuspage => CreateStatuspage::make('Acme')->withSlug(''), 'slug must not be blank'],
    'blank slug in the constructor' => [fn(): CreateStatuspage => new CreateStatuspage('Acme', ' '), 'slug must not be blank'],
]);
