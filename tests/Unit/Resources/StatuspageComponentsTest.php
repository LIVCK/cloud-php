<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\ComponentBuilder;
use LIVCK\Cloud\Data\Reference;
use LIVCK\Cloud\Data\StatuspageComponent;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\ComponentStatus;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Payloads\UpdateComponent;
use LIVCK\Cloud\Support\Uuid;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use LIVCK\Cloud\Tests\Fixtures\StatuspageFixtures;

describe('all', function (): void {
    it('lists the flat component list of the page, not paginated', function (): void {
        $rows = [
            StatuspageFixtures::group(),
            StatuspageFixtures::component(),
            StatuspageFixtures::syncedGroup(),
            StatuspageFixtures::syncManagedChild(),
        ];
        [$client, $http] = fakeClient([MockResponse::json(['data' => $rows])]);

        $components = $client->statuspages()->components(StatuspageFixtures::PAGE_ID)->all();

        expect($components)->toHaveCount(4)
            ->and(array_map(static fn(StatuspageComponent $c): string => $c->id, $components))->toBe([
                StatuspageFixtures::GROUP_ID,
                StatuspageFixtures::COMPONENT_ID,
                StatuspageFixtures::SYNCED_GROUP_ID,
                'MnGd1C2h3I4l5D6x7Y8z9',
            ]);

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', StatuspageFixtures::COMPONENTS_PATH) && $r->queryString() === '');
    });

    it('hydrates every field of a service component', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => [StatuspageFixtures::component()]])]);

        $component = $client->statuspages()->components(StatuspageFixtures::PAGE_ID)->all()[0];

        expect($component->id)->toBe(StatuspageFixtures::COMPONENT_ID)
            ->and($component->name)->toBe('Website')
            ->and($component->nameTranslations)->toBe(['en' => 'Website'])
            ->and($component->description)->toBe('Shop and customer portal')
            ->and($component->descriptionTranslations)->toBe(['en' => 'Shop and customer portal'])
            ->and($component->status)->toBe(ComponentStatus::Operational)
            ->and($component->effectiveStatus)->toBe(ComponentStatus::Degraded)
            ->and($component->isGroup)->toBeFalse()
            ->and($component->isVisible)->toBeTrue()
            ->and($component->displayOrder)->toBe(3)
            ->and($component->showUptimeBars)->toBeTrue()
            ->and($component->hideOperationalChildren)->toBeFalse()
            ->and($component->defaultOpen)->toBeTrue()
            ->and($component->parentId)->toBe(StatuspageFixtures::GROUP_ID)
            ->and($component->syncTagId)->toBeNull()
            ->and($component->syncNewVisible)->toBeTrue()
            ->and($component->isSyncManaged)->toBeFalse()
            ->and($component->isSyncedGroup())->toBeFalse()
            ->and($component->service)->toBeInstanceOf(Reference::class)
            ->and($component->service?->id)->toBe(StatuspageFixtures::SERVICE_ID)
            ->and($component->service?->name)->toBe('acme-web')
            ->and($component->service?->raw)->toBe(['id' => StatuspageFixtures::SERVICE_ID, 'name' => 'acme-web'])
            ->and($component->raw)->toBe(StatuspageFixtures::component());
    });

    it('hydrates a synced group and a child it manages', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => [StatuspageFixtures::syncedGroup(), StatuspageFixtures::syncManagedChild()]])]);

        [$group, $child] = $client->statuspages()->components(StatuspageFixtures::PAGE_ID)->all();

        expect($group->isGroup)->toBeTrue()
            ->and($group->isSyncedGroup())->toBeTrue()
            ->and($group->syncTagId)->toBe(StatuspageFixtures::TAG_ID)
            ->and($group->syncNewVisible)->toBeFalse()
            ->and($group->hideOperationalChildren)->toBeTrue()
            ->and($group->defaultOpen)->toBeFalse()
            ->and($group->parentId)->toBeNull()
            ->and($group->service)->toBeNull()
            ->and($group->isSyncManaged)->toBeFalse()
            ->and($child->isSyncManaged)->toBeTrue()
            ->and($child->parentId)->toBe($group->id)
            ->and($child->isVisible)->toBeFalse()
            ->and($child->effectiveStatus)->toBeNull()
            ->and($child->description)->toBeNull()
            ->and($child->descriptionTranslations)->toBeNull()
            ->and($child->service?->id)->toBe('Sv1mAiL2b3C4d5E6f7G8h');
    });

    it('maps every status the server sends', function (string $wire, ComponentStatus $status): void {
        [$client] = fakeClient([MockResponse::json(['data' => [StatuspageFixtures::component(['status' => $wire, 'effective_status' => $wire])]])]);

        $component = $client->statuspages()->components('p')->all()[0];

        expect($component->status)->toBe($status)
            ->and($component->effectiveStatus)->toBe($status);
    })->with([
        ['operational', ComponentStatus::Operational],
        ['degraded', ComponentStatus::Degraded],
        ['partial_outage', ComponentStatus::PartialOutage],
        ['major_outage', ComponentStatus::MajorOutage],
        ['under_maintenance', ComponentStatus::UnderMaintenance],
        ['unknown', ComponentStatus::Unknown],
    ]);

    it('keeps an unknown status readable', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => [StatuspageFixtures::component(['effective_status' => 'on_fire'])]])]);

        $component = $client->statuspages()->components('p')->all()[0];

        expect($component->effectiveStatus)->toBe(ComponentStatus::Unrecognized)
            ->and($component->effectiveStatus?->isUnrecognized())->toBeTrue()
            ->and($component->raw['effective_status'])->toBe('on_fire');
    });

    it('returns an empty list for a page without components', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => []])]);

        expect($client->statuspages()->components('p')->all())->toBe([]);
    });

    it('percent-encodes the bound page id', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => []])]);

        $client->statuspages()->components('odd/page')->all();

        expect($http->lastRequest()?->uri)->toBe('https://api.livck.cloud/v1/statuspages/odd%2Fpage/components');
    });
});

describe('get', function (): void {
    it('fetches one component of the page', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::group()])]);

        $group = $client->statuspages()->components(StatuspageFixtures::PAGE_ID)->get(StatuspageFixtures::GROUP_ID);

        expect($group->name)->toBe('Infrastructure')
            ->and($group->nameTranslations)->toBe(['de' => 'Infrastruktur', 'en' => 'Infrastructure']);
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', StatuspageFixtures::COMPONENTS_PATH . '/' . StatuspageFixtures::GROUP_ID));
    });

    it('raises not found for a component of another page', function (): void {
        [$client] = singleShotClient([MockResponse::error('The requested resource was not found.', 404)]);

        expect(fn(): StatuspageComponent => $client->statuspages()->components('p')->get('elsewhere'))->toThrow(NotFoundException::class);
    });
});

describe('create', function (): void {
    it('creates a group synced to a tag', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::syncedGroup()], 201)]);
        $tag = Tag::fromArray(tagPayload());

        $group = $client->statuspages()->components(StatuspageFixtures::PAGE_ID)->create(
            ComponentBuilder::syncedGroup('Your services', $tag)->syncNewVisible(false)->hideOperationalChildren(true),
        );

        expect($group->isSyncedGroup())->toBeTrue();
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', StatuspageFixtures::COMPONENTS_PATH)
            && $r->json() === [
                'name' => 'Your services',
                'is_group' => true,
                'sync_tag_id' => StatuspageFixtures::TAG_ID,
                'sync_new_visible' => false,
                'hide_operational_children' => true,
            ]
            && $r->idempotencyKey() !== null);
    });

    it('creates a service component inside a group', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::component()], 201)]);
        $group = StatuspageComponent::fromArray(StatuspageFixtures::group());

        $client->statuspages()->components(StatuspageFixtures::PAGE_ID)->create(
            ComponentBuilder::service(StatuspageFixtures::SERVICE_ID, 'Website')->parent($group)->showUptimeBars(true),
        );

        expect($http->lastRequest()?->json())->toBe([
            'name' => 'Website',
            'service_id' => StatuspageFixtures::SERVICE_ID,
            'parent_id' => StatuspageFixtures::GROUP_ID,
            'show_uptime_bars' => true,
        ]);
    });

    it('creates a plain group and a manual component', function (ComponentBuilder $builder, string $body): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::group()], 201)]);

        $client->statuspages()->components('p')->create($builder);

        expect($http->lastRequest()?->body)->toBe($body);
    })->with([
        'group' => [fn(): ComponentBuilder => ComponentBuilder::group('Infrastructure'), '{"name":"Infrastructure","is_group":true}'],
        'manual' => [fn(): ComponentBuilder => ComponentBuilder::manual('Phone support')->displayOrder(0), '{"name":"Phone support","display_order":0}'],
    ]);

    it('raises not found for a parent that is not on this page', function (): void {
        [$client] = singleShotClient([MockResponse::error('The requested resource was not found.', 404)]);

        expect(fn(): StatuspageComponent => $client->statuspages()->components('p')->create(ComponentBuilder::manual('X')->parent('unknown-parent')))
            ->toThrow(NotFoundException::class);
    });

    it('surfaces the structural rules as validation errors', function (string $field, string $message): void {
        [$client] = singleShotClient([MockResponse::error($message, 422, [$field => [$message]])]);

        try {
            $client->statuspages()->components('p')->create(ComponentBuilder::syncedGroup('Services', StatuspageFixtures::TAG_ID));
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->firstError($field))->toBe($message);
        }
    })->with([
        'parent is no group' => ['parent_id', 'Components can only be nested under groups.'],
        'system tag' => ['sync_tag_id', 'This tag is maintained by the server agent and cannot drive a group. Pick a tag you created yourself.'],
        'too deep' => ['sync_tag_id', 'This group is nested too deep — synced services would exceed the maximum depth.'],
        'duplicate service' => ['service_id', 'This service already exists in the synced group.'],
    ]);

    it('sends the given idempotency key instead of a generated one', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::group()], 201)]);

        $client->statuspages()->components('p')->create(ComponentBuilder::group('Infrastructure'), 'onboard-4711-group');

        expect($http->lastRequest()?->idempotencyKey())->toBe('onboard-4711-group');
    });

    it('generates a UUID v4 key when none is given', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::group()], 201)]);

        $client->statuspages()->components('p')->create(ComponentBuilder::group('Infrastructure'));

        expect(Uuid::isV4((string) $http->lastRequest()?->idempotencyKey()))->toBeTrue();
    });

    it('refuses a malformed idempotency key before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): StatuspageComponent => $client->statuspages()->components('p')->create(ComponentBuilder::group('Infrastructure'), 'has space'))
            ->toThrow(InvalidArgumentException::class, 'visible ASCII');
        $http->assertNothingSent();
    });
});

describe('update', function (): void {
    it('patches only what was set', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::syncManagedChild(['name' => 'Mail', 'is_visible' => true])])]);

        $child = $client->statuspages()->components(StatuspageFixtures::PAGE_ID)->update(
            'MnGd1C2h3I4l5D6x7Y8z9',
            UpdateComponent::make()->withName('Mail')->withIsVisible(true),
        );

        expect($child->name)->toBe('Mail')
            ->and($child->isVisible)->toBeTrue();
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('PATCH', StatuspageFixtures::COMPONENTS_PATH . '/MnGd1C2h3I4l5D6x7Y8z9')
            && $r->json() === ['name' => 'Mail', 'is_visible' => true]
            && ! $r->hasHeader('Idempotency-Key'));
    });

    it('detaches the sync of a group with an explicit null', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::syncedGroup(['sync_tag_id' => null])])]);

        $group = $client->statuspages()->components('p')->update(StatuspageFixtures::SYNCED_GROUP_ID, UpdateComponent::make()->withoutSyncTag());

        expect($group->isSyncedGroup())->toBeFalse()
            ->and($http->lastRequest()?->body)->toBe('{"sync_tag_id":null}');
    });

    it('surfaces a locked service or parent of a sync-managed component', function (string $field): void {
        $message = 'This component is managed by tag sync — its service and group cannot be changed.';
        [$client] = singleShotClient([MockResponse::error($message, 422, [$field => [$message]])]);

        try {
            $client->statuspages()->components('p')->update('child', UpdateComponent::make()->withoutParent()->withoutService());
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->hasError($field))->toBeTrue();
        }
    })->with(['service_id', 'parent_id']);

    it('refuses an empty update before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): StatuspageComponent => $client->statuspages()->components('p')->update('c', UpdateComponent::make()))
            ->toThrow(InvalidArgumentException::class, 'no changes');
        $http->assertNothingSent();
    });
});

describe('delete', function (): void {
    it('deletes and returns nothing', function (): void {
        [$client, $http] = fakeClient([MockResponse::noContent()]);

        $client->statuspages()->components(StatuspageFixtures::PAGE_ID)->delete(StatuspageFixtures::GROUP_ID);

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('DELETE', StatuspageFixtures::COMPONENTS_PATH . '/' . StatuspageFixtures::GROUP_ID) && $r->body === '');

        expect($http->recorded())->toHaveCount(1);
    });

    it('refuses to delete a component a synced group manages', function (): void {
        $message = 'This component is managed by tag sync. Untag the service or detach the group sync instead.';
        [$client] = singleShotClient([MockResponse::error($message, 422, ['component' => [$message]])]);

        try {
            $client->statuspages()->components('p')->delete('MnGd1C2h3I4l5D6x7Y8z9');
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->hasError('component'))->toBeTrue()
                ->and($e->errorMessage())->toBe($message);
        }
    });
});
