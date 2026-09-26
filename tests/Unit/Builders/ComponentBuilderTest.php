<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\ComponentBuilder;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\StatuspageComponent;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Translatable;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;
use LIVCK\Cloud\Tests\Fixtures\StatuspageFixtures;

it('builds every kind of component', function (ComponentBuilder $builder, array $expected): void {
    expect($builder->toArray())->toBe($expected);
})->with([
    'group' => [fn(): ComponentBuilder => ComponentBuilder::group('Infrastructure'), ['name' => 'Infrastructure', 'is_group' => true]],
    'synced group from a tag id' => [
        fn(): ComponentBuilder => ComponentBuilder::syncedGroup('Your services', StatuspageFixtures::TAG_ID),
        ['name' => 'Your services', 'is_group' => true, 'sync_tag_id' => StatuspageFixtures::TAG_ID],
    ],
    'synced group from a tag' => [
        fn(): ComponentBuilder => ComponentBuilder::syncedGroup('Your services', Tag::fromArray(tagPayload(['id' => 'TagFromDto0123456789a']))),
        ['name' => 'Your services', 'is_group' => true, 'sync_tag_id' => 'TagFromDto0123456789a'],
    ],
    'service component' => [
        fn(): ComponentBuilder => ComponentBuilder::service(StatuspageFixtures::SERVICE_ID, 'Website'),
        ['name' => 'Website', 'service_id' => StatuspageFixtures::SERVICE_ID],
    ],
    'manual component' => [fn(): ComponentBuilder => ComponentBuilder::manual('Phone support'), ['name' => 'Phone support']],
]);

it('sends names and descriptions in several languages as locale maps', function (): void {
    $builder = ComponentBuilder::group(Translatable::translations(['de' => 'Infrastruktur', 'en' => 'Infrastructure']))
        ->description(Translatable::translations(['de' => 'Rechenzentren', 'en' => 'Data centres']));

    expect($builder->toArray())->toBe([
        'name' => ['de' => 'Infrastruktur', 'en' => 'Infrastructure'],
        'is_group' => true,
        'description' => ['de' => 'Rechenzentren', 'en' => 'Data centres'],
    ]);
});

it('adds every option in the order it was set', function (): void {
    $builder = ComponentBuilder::syncedGroup('Your services', StatuspageFixtures::TAG_ID)
        ->parent(StatuspageFixtures::GROUP_ID)
        ->description('Everything we host for you')
        ->visible(false)
        ->displayOrder(2)
        ->showUptimeBars(true)
        ->hideOperationalChildren(true)
        ->defaultOpen(false)
        ->syncNewVisible(false);

    expect($builder->toArray())->toBe([
        'name' => 'Your services',
        'is_group' => true,
        'sync_tag_id' => StatuspageFixtures::TAG_ID,
        'parent_id' => StatuspageFixtures::GROUP_ID,
        'description' => 'Everything we host for you',
        'is_visible' => false,
        'display_order' => 2,
        'show_uptime_bars' => true,
        'hide_operational_children' => true,
        'default_open' => false,
        'sync_new_visible' => false,
    ]);
});

it('takes the parent from a component it was given', function (): void {
    $group = StatuspageComponent::fromArray(StatuspageFixtures::group());

    expect(ComponentBuilder::manual('Mail')->parent($group)->toArray())->toBe(['name' => 'Mail', 'parent_id' => StatuspageFixtures::GROUP_ID]);
});

it('is immutable', function (): void {
    $base = ComponentBuilder::manual('Mail');
    $hidden = $base->visible(false);

    expect($base->toArray())->toBe(['name' => 'Mail'])
        ->and($hidden->toArray())->toBe(['name' => 'Mail', 'is_visible' => false]);
});

it('refuses blank names and ids', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank name' => [fn(): ComponentBuilder => ComponentBuilder::group(' '), 'must not be blank'],
    'blank description' => [fn(): ComponentBuilder => ComponentBuilder::group('Infrastructure')->description(''), 'must not be blank'],
    'blank service id' => [fn(): ComponentBuilder => ComponentBuilder::service(' ', 'Website'), 'service id must not be blank'],
    'blank tag id' => [fn(): ComponentBuilder => ComponentBuilder::syncedGroup('Services', ''), 'is not a tag id'],
    'tag label instead of an id' => [fn(): ComponentBuilder => ComponentBuilder::syncedGroup('Services', 'kunde:4711'), 'is not a tag id'],
    'blank parent id' => [fn(): ComponentBuilder => ComponentBuilder::manual('Mail')->parent(' '), 'parent id must not be blank'],
]);

it('takes the component name from a Service unless one is given', function (): void {
    $service = Service::fromArray(ServiceFixtures::payload(['name' => 'Customer shop']));

    expect(ComponentBuilder::service($service)->toArray())
        ->toBe(['name' => 'Customer shop', 'service_id' => ServiceFixtures::ID])
        ->and(ComponentBuilder::service($service, 'Shop')->toArray())
        ->toBe(['name' => 'Shop', 'service_id' => ServiceFixtures::ID]);
});

it('needs a name when only a service id is given', function (): void {
    expect(fn(): ComponentBuilder => ComponentBuilder::service(ServiceFixtures::ID))
        ->toThrow(InvalidArgumentException::class, 'Pass a component name, or the Service itself so its name can be used.');
});
