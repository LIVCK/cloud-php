<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\Statuspage;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\Translatable;
use LIVCK\Cloud\Tests\Fixtures\StatuspageFixtures;

it('tells a response without components from a page without components', function (): void {
    expect(Statuspage::fromArray(StatuspageFixtures::listedPage())->components)->toBeNull()
        ->and(Statuspage::fromArray(StatuspageFixtures::page(['components' => null]))->components)->toBeNull()
        ->and(Statuspage::fromArray(StatuspageFixtures::page(['components' => []]))->components)->toBe([]);
});

it('reads the counters and dates the asset endpoints leave out as null', function (): void {
    $page = Statuspage::fromArray(StatuspageFixtures::assetResponsePage(['created_at' => null]));

    expect($page->componentsCount)->toBeNull()
        ->and($page->components)->toBeNull()
        ->and($page->createdAt)->toBeNull();
});

it('reads the page languages and the name translations as plain maps', function (): void {
    $page = Statuspage::fromArray(StatuspageFixtures::page([
        'supported_locales' => ['en'],
        'name_translations' => ['de' => 'Acme', 'en' => ''],
    ]));

    expect($page->supportedLocales)->toBe(['en'])
        ->and($page->hasOwnLocales())->toBeTrue()
        ->and($page->nameTranslations)->toBe(['de' => 'Acme', 'en' => '']);
});

it('reads a missing translation map as null', function (): void {
    expect(Statuspage::fromArray(StatuspageFixtures::page(['name_translations' => null]))->nameTranslations)->toBeNull();
});

it('turns a translation map back into input for an update', function (): void {
    $page = Statuspage::fromArray(StatuspageFixtures::customizedPage());

    expect(Translatable::translations($page->nameTranslations ?? [])->jsonSerialize())->toBe(['de' => 'Acme Status', 'en' => 'Acme Status EN']);
});

it('fails with the field name when the payload drifts', function (Closure $build, string $message): void {
    expect($build)->toThrow(UnexpectedResponseException::class, $message);
})->with([
    'translation that is no string' => [
        fn(): Statuspage => Statuspage::fromArray(StatuspageFixtures::page(['name_translations' => ['de' => 42]])),
        'Field "name_translations.de": expected string, got int.',
    ],
    'translations that are a list' => [
        fn(): Statuspage => Statuspage::fromArray(StatuspageFixtures::page(['name_translations' => ['Acme']])),
        'Field "name_translations": expected object, got array.',
    ],
    'components that are no list' => [
        fn(): Statuspage => Statuspage::fromArray(StatuspageFixtures::page(['components' => 'none'])),
        'Field "components": expected array, got string.',
    ],
    'missing slug' => [
        fn(): Statuspage => Statuspage::fromArray(StatuspageFixtures::page(['slug' => null])),
        'Field "slug": expected string, got null.',
    ],
    'malformed created_at' => [
        fn(): Statuspage => Statuspage::fromArray(StatuspageFixtures::page(['created_at' => 'yesterday'])),
        'Field "created_at": expected ISO 8601 instant',
    ],
]);
