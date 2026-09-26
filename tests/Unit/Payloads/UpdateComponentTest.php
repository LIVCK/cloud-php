<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\StatuspageComponent;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Payloads\UpdateComponent;
use LIVCK\Cloud\Support\Translatable;
use LIVCK\Cloud\Tests\Fixtures\StatuspageFixtures;

it('starts empty', function (): void {
    expect(UpdateComponent::make()->isEmpty())->toBeTrue()
        ->and(UpdateComponent::make()->toArray())->toBe([]);
});

it('maps every setter to its API field', function (UpdateComponent $update, array $expected): void {
    expect($update->isEmpty())->toBeFalse()
        ->and($update->toArray())->toBe($expected);
})->with([
    'name' => [fn(): UpdateComponent => UpdateComponent::make()->withName('Website'), ['name' => 'Website']],
    'name in languages' => [
        fn(): UpdateComponent => UpdateComponent::make()->withName(Translatable::translations(['de' => 'Webseite', 'en' => 'Website'])),
        ['name' => ['de' => 'Webseite', 'en' => 'Website']],
    ],
    'description' => [fn(): UpdateComponent => UpdateComponent::make()->withDescription('Shop'), ['description' => 'Shop']],
    'service' => [fn(): UpdateComponent => UpdateComponent::make()->withService(StatuspageFixtures::SERVICE_ID), ['service_id' => StatuspageFixtures::SERVICE_ID]],
    'parent id' => [fn(): UpdateComponent => UpdateComponent::make()->withParent(StatuspageFixtures::GROUP_ID), ['parent_id' => StatuspageFixtures::GROUP_ID]],
    'parent component' => [
        fn(): UpdateComponent => UpdateComponent::make()->withParent(StatuspageComponent::fromArray(StatuspageFixtures::syncedGroup())),
        ['parent_id' => StatuspageFixtures::SYNCED_GROUP_ID],
    ],
    'is group' => [fn(): UpdateComponent => UpdateComponent::make()->withIsGroup(true), ['is_group' => true]],
    'is visible' => [fn(): UpdateComponent => UpdateComponent::make()->withIsVisible(false), ['is_visible' => false]],
    'display order' => [fn(): UpdateComponent => UpdateComponent::make()->withDisplayOrder(10), ['display_order' => 10]],
    'uptime bars' => [fn(): UpdateComponent => UpdateComponent::make()->withShowUptimeBars(true), ['show_uptime_bars' => true]],
    'hide operational children' => [fn(): UpdateComponent => UpdateComponent::make()->withHideOperationalChildren(true), ['hide_operational_children' => true]],
    'default open' => [fn(): UpdateComponent => UpdateComponent::make()->withDefaultOpen(false), ['default_open' => false]],
    'sync tag id' => [fn(): UpdateComponent => UpdateComponent::make()->withSyncTag(StatuspageFixtures::TAG_ID), ['sync_tag_id' => StatuspageFixtures::TAG_ID]],
    'sync tag' => [
        fn(): UpdateComponent => UpdateComponent::make()->withSyncTag(Tag::fromArray(tagPayload(['id' => 'TagFromDto0123456789a']))),
        ['sync_tag_id' => 'TagFromDto0123456789a'],
    ],
    'sync new visible' => [fn(): UpdateComponent => UpdateComponent::make()->withSyncNewVisible(false), ['sync_new_visible' => false]],
]);

it('sends explicit nulls to remove links and values', function (UpdateComponent $update, array $expected): void {
    expect($update->toArray())->toBe($expected);
})->with([
    'description' => [fn(): UpdateComponent => UpdateComponent::make()->withoutDescription(), ['description' => null]],
    'description through the setter' => [fn(): UpdateComponent => UpdateComponent::make()->withDescription(null), ['description' => null]],
    'service' => [fn(): UpdateComponent => UpdateComponent::make()->withoutService(), ['service_id' => null]],
    'parent' => [fn(): UpdateComponent => UpdateComponent::make()->withoutParent(), ['parent_id' => null]],
    'sync tag' => [fn(): UpdateComponent => UpdateComponent::make()->withoutSyncTag(), ['sync_tag_id' => null]],
]);

it('turns a synced group back into a component in one update', function (): void {
    expect(UpdateComponent::make()->withoutSyncTag()->withIsGroup(false)->toArray())->toBe(['sync_tag_id' => null, 'is_group' => false]);
});

it('is immutable', function (): void {
    $base = UpdateComponent::make()->withName('Website');
    $moved = $base->withoutParent();

    expect($base->toArray())->toBe(['name' => 'Website'])
        ->and($moved->toArray())->toBe(['name' => 'Website', 'parent_id' => null]);
});

it('refuses blank names and ids', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank name' => [fn(): UpdateComponent => UpdateComponent::make()->withName(''), 'must not be blank'],
    'blank description' => [fn(): UpdateComponent => UpdateComponent::make()->withDescription(' '), 'must not be blank'],
    'blank service id' => [fn(): UpdateComponent => UpdateComponent::make()->withService(''), 'service id must not be blank'],
    'blank parent id' => [fn(): UpdateComponent => UpdateComponent::make()->withParent(' '), 'parent id must not be blank'],
    'blank tag id' => [fn(): UpdateComponent => UpdateComponent::make()->withSyncTag(''), 'is not a tag id'],
    'tag label instead of an id' => [fn(): UpdateComponent => UpdateComponent::make()->withSyncTag('kunde:4711'), 'is not a tag id'],
]);
