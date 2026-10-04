<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\ComponentBuilder;
use LIVCK\Cloud\Data\CustomDomain;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\Statuspage;
use LIVCK\Cloud\Data\StatuspageComponent;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\AccessType;
use LIVCK\Cloud\Enums\AssetType;
use LIVCK\Cloud\Enums\ComponentStatus;
use LIVCK\Cloud\Enums\CustomDomainErrorCode;
use LIVCK\Cloud\Enums\CustomDomainStatus;
use LIVCK\Cloud\Enums\StatuspageAppearance;
use LIVCK\Cloud\Enums\SubscriberChannel;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PlanLimitException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Payloads\AssetFile;
use LIVCK\Cloud\Payloads\UpdateComponent;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Payloads\UpdateStatuspage;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 7, status pages per customer: a page per end customer with a group synced to
 * the customer's tag, the children following a service from one customer to the next,
 * manual and service components with their updates, publishing, a logo, a custom domain
 * with its verification cooldown, and the guard that keeps a synced tag from being deleted.
 */
$skip = LiveApi::skipReason();

describe('scenario 7: status pages per customer', function () use ($skip): void {
    it('keeps a synced page per customer and edits everything on it', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $cleanupFailures = [];

        /**
         * The sync-managed children of a group, keyed by the id of the service they stand for.
         *
         * @return array<string, StatuspageComponent>
         */
        $childrenOf = static function (string $pageId, string $groupId) use ($client): array {
            $children = [];

            foreach (DtoAudit::inspect($client->statuspages()->components($pageId)->all(), 'components.all') as $component) {
                if ($component->parentId === $groupId && $component->isSyncManaged && $component->service !== null) {
                    $children[$component->service->id] = $component;
                }
            }

            return $children;
        };

        try {
            $customers = $journey->step('1 three customers: a tag, two manual services, a page with a synced group', function () use ($client, $cleanup): array {
                $customers = [];

                foreach (['a', 'b', 'c'] as $customer) {
                    $tag = Scenario::customerTag($client, $cleanup, $customer);
                    $services = [
                        Scenario::manualService($client, $cleanup, 'sp-' . $customer . '1', $tag),
                        Scenario::manualService($client, $cleanup, 'sp-' . $customer . '2', $tag),
                    ];
                    $page = Scenario::statuspage($client, $cleanup, $customer);

                    expect($page->slug)->toBe(Scenario::name($customer))
                        ->and($page->name)->toBe('Status ' . Scenario::name($customer))
                        ->and($page->url)->toContain($page->slug)
                        ->and($page->components)->toBe([])
                        ->and($page->accessType)->toBe(AccessType::Public)
                        ->and($page->hasPassword)->toBeFalse()
                        ->and($page->emailWhitelist)->toBe([])
                        ->and($page->subscriberChannels)->toContain(SubscriberChannel::Email)
                        ->and($page->effectiveSupportedLocales)->toContain($page->effectiveDefaultLocale)
                        ->and($page->hasOwnLocales())->toBeFalse()
                        ->and($page->theme)->not->toBe('')
                        ->and($page->appearance)->toBe(StatuspageAppearance::System)
                        ->and($page->allowAppearanceSwitch)->toBeTrue()
                        ->and($page->createdAt)->not->toBeNull();

                    $found = DtoAudit::inspect($client->statuspages()->findBySlug($page->slug), 'statuspages.findBySlug');

                    expect($found?->id)->toBe($page->id)
                        ->and($found?->components)->toBeNull()
                        ->and($client->statuspages()->findBySlug(Scenario::name('nobody')))->toBeNull();

                    $group = DtoAudit::inspect(
                        $client->statuspages()->components($page->id)->create(ComponentBuilder::syncedGroup('Services ' . strtoupper($customer), $tag)->syncNewVisible()->showUptimeBars()->defaultOpen()),
                        'components.create (synced group)',
                    );

                    expect($group->isGroup)->toBeTrue()
                        ->and($group->isSyncedGroup())->toBeTrue()
                        ->and($group->syncTagId)->toBe($tag->id)
                        ->and($group->syncNewVisible)->toBeTrue()
                        ->and($group->showUptimeBars)->toBeTrue()
                        ->and($group->defaultOpen)->toBeTrue()
                        ->and($group->isSyncManaged)->toBeFalse()
                        ->and($group->parentId)->toBeNull()
                        ->and($group->service)->toBeNull()
                        ->and($group->isVisible)->toBeTrue()
                        ->and($group->name)->toBe('Services ' . strtoupper($customer));

                    $customers[$customer] = ['tag' => $tag, 'services' => $services, 'page' => $page, 'group' => $group];
                }

                return $customers;
            });

            $journey->step('2 the synced children equal each customer\'s services (polled, at most 60 s)', function () use ($customers, $childrenOf, $journey): void {
                foreach ($customers as $customer => ['services' => $services, 'page' => $page, 'group' => $group]) {
                    $wanted = array_map(static fn(Service $service): string => $service->id, $services);
                    [$children, $elapsed] = Scenario::waitFor(60, static function () use ($childrenOf, $page, $group, $wanted): ?array {
                        $children = $childrenOf($page->id, $group->id);
                        $ids = array_keys($children);
                        sort($ids);
                        sort($wanted);

                        return $ids === $wanted ? $children : null;
                    });

                    expect($children)->toBeArray('customer ' . $customer . ': the synced group did not receive the services within 60 s.');

                    foreach (is_array($children) ? $children : [] as $child) {
                        expect($child)->toBeInstanceOf(StatuspageComponent::class);

                        if (! $child instanceof StatuspageComponent) {
                            continue;
                        }

                        expect($child->isSyncManaged)->toBeTrue()
                            ->and($child->isGroup)->toBeFalse()
                            ->and($child->isVisible)->toBeTrue()
                            ->and($child->effectiveStatus)->toBe(ComponentStatus::Operational)
                            ->and($child->service?->name)->toBe($child->name)
                            ->and($child->showUptimeBars)->toBeTrue();
                    }

                    $journey->note(sprintf('customer %s: %d children in sync after %.1f s', $customer, is_array($children) ? count($children) : 0, $elapsed));
                }
            });

            $journey->step('3 moving a service from customer A to B moves its component (polled, at most 90 s)', function () use ($client, $customers, $childrenOf, $journey): void {
                $moving = $customers['a']['services'][1];
                $client->services()->update($moving->id, UpdateService::make()->withTags($customers['b']['tag']));

                [$done, $elapsed] = Scenario::waitFor(90, static function () use ($childrenOf, $customers, $moving): ?bool {
                    $a = array_keys($childrenOf($customers['a']['page']->id, $customers['a']['group']->id));
                    $b = array_keys($childrenOf($customers['b']['page']->id, $customers['b']['group']->id));

                    return $a === [$customers['a']['services'][0]->id] && in_array($moving->id, $b, true) && count($b) === 3 ? true : null;
                }, 3.0);

                $journey->note(sprintf('component moved after %.1f s', $elapsed));

                expect($done)->toBeTrue('The component did not follow the service from customer A to customer B within 90 s.');

                $pageA = DtoAudit::inspect($client->statuspages()->get($customers['a']['page']->id), 'statuspages.get');

                expect($pageA->components)->toHaveCount(2)
                    ->and($pageA->componentsCount)->toBe(2);
            });

            $journey->step('4 a group with a service component and a manual component; update, move, delete', function () use ($client, $customers): void {
                $page = $customers['a']['page'];
                $serviceA1 = $customers['a']['services'][0];
                $components = $client->statuspages()->components($page->id);

                $infra = DtoAudit::inspect($components->create(ComponentBuilder::group('Infrastructure')->hideOperationalChildren()), 'components.create (group)');
                $web = DtoAudit::inspect($components->create(ComponentBuilder::service($serviceA1)->parent($infra)->showUptimeBars()), 'components.create (service)');
                $phone = DtoAudit::inspect($components->create(ComponentBuilder::manual('Phone support')->description('Hotline and callbacks')->displayOrder(500)), 'components.create (manual)');

                expect($infra->isGroup)->toBeTrue()
                    ->and($infra->hideOperationalChildren)->toBeTrue()
                    ->and($infra->syncTagId)->toBeNull()
                    ->and($web->name)->toBe($serviceA1->name)
                    ->and($web->service?->id)->toBe($serviceA1->id)
                    ->and($web->parentId)->toBe($infra->id)
                    ->and($web->isSyncManaged)->toBeFalse()
                    ->and($web->effectiveStatus)->toBe(ComponentStatus::Operational)
                    ->and($phone->service)->toBeNull()
                    ->and($phone->isGroup)->toBeFalse()
                    ->and($phone->description)->toBe('Hotline and callbacks')
                    ->and($phone->displayOrder)->toBe(500)
                    ->and($phone->effectiveStatus)->toBe(ComponentStatus::Operational);

                $hidden = DtoAudit::inspect($components->update($web->id, UpdateComponent::make()->withName('Web')->withDescription('Front door')->withDisplayOrder(3)->withIsVisible(false)), 'components.update');

                expect($hidden->name)->toBe('Web')
                    ->and($hidden->description)->toBe('Front door')
                    ->and($hidden->displayOrder)->toBe(3)
                    ->and($hidden->isVisible)->toBeFalse()
                    ->and($hidden->effectiveStatus)->toBeNull();

                $shown = $components->update($web->id, UpdateComponent::make()->withIsVisible(true)->withoutDescription());
                $fetched = DtoAudit::inspect($components->get($web->id), 'components.get');

                expect($shown->isVisible)->toBeTrue()
                    ->and($shown->description)->toBeNull()
                    ->and($shown->effectiveStatus)->toBe(ComponentStatus::Operational)
                    ->and($fetched->name)->toBe('Web')
                    ->and($fetched->parentId)->toBe($infra->id);

                $moved = $components->update($phone->id, UpdateComponent::make()->withParent($infra));
                $top = $components->update($phone->id, UpdateComponent::make()->withoutParent());
                $unlinked = $components->update($web->id, UpdateComponent::make()->withoutService());

                expect($moved->parentId)->toBe($infra->id)
                    ->and($top->parentId)->toBeNull()
                    ->and($unlinked->service)->toBeNull();

                $components->delete($phone->id);

                expect(static fn(): StatuspageComponent => $components->get($phone->id))->toThrow(NotFoundException::class);

                // A child a synced group added cannot be deleted by itself.
                $syncChild = $components->all();
                $managed = array_values(array_filter($syncChild, static fn(StatuspageComponent $component): bool => $component->isSyncManaged));

                expect($managed)->not->toBe([]);

                try {
                    $components->delete($managed[0]->id);
                    expect(false)->toBeTrue('A sync-managed component was deleted by itself.');
                } catch (ValidationException $e) {
                    expect($e->hasError('component'))->toBeTrue()
                        ->and($e->status())->toBe(422);
                }

                // Deleting the group takes its children along.
                $components->delete($infra->id);

                expect(static fn(): StatuspageComponent => $components->get($web->id))->toThrow(NotFoundException::class);
            });

            $journey->step('5 unsyncing a group leaves its children as ordinary, deletable components', function () use ($client, $customers): void {
                $page = $customers['c']['page'];
                $components = $client->statuspages()->components($page->id);
                $plain = DtoAudit::inspect($components->update($customers['c']['group']->id, UpdateComponent::make()->withoutSyncTag()), 'components.update (unsync)');
                $children = array_values(array_filter($components->all(), static fn(StatuspageComponent $component): bool => $component->parentId === $plain->id));

                expect($plain->syncTagId)->toBeNull()
                    ->and($plain->isSyncedGroup())->toBeFalse()
                    ->and($plain->isGroup)->toBeTrue()
                    ->and($children)->toHaveCount(2)
                    ->and($children[0]->isSyncManaged)->toBeFalse();

                $components->delete($children[0]->id);

                expect(static fn(): StatuspageComponent => $components->get($children[0]->id))->toThrow(NotFoundException::class);
            });

            $journey->step('6 unpublish and publish; the update payload', function () use ($client, $customers): void {
                $page = $customers['a']['page'];
                $offline = DtoAudit::inspect($client->statuspages()->unpublish($page->id), 'statuspages.unpublish');
                $online = DtoAudit::inspect($client->statuspages()->publish($page->id), 'statuspages.publish');
                $updated = DtoAudit::inspect($client->statuspages()->update($page->id, UpdateStatuspage::make()
                    ->withPrimaryColor('#0f172a')
                    ->withShowLivi(false)
                    ->withImprintUrl('https://example.com/imprint')
                    ->withAppearance(StatuspageAppearance::Dark)
                    ->withAllowAppearanceSwitch(false)), 'statuspages.update');
                $cleared = $client->statuspages()->update($page->id, UpdateStatuspage::make()
                    ->withoutPrimaryColor()
                    ->withoutImprintUrl()
                    ->withAppearance(StatuspageAppearance::System)
                    ->withAllowAppearanceSwitch(true));

                expect($offline->isPublished)->toBeFalse()
                    ->and($offline->components)->not->toBeNull()
                    ->and($online->isPublished)->toBeTrue()
                    ->and($online->url)->toBe($page->url)
                    ->and($updated->primaryColor)->toBe('#0f172a')
                    ->and($updated->showLivi)->toBeFalse()
                    ->and($updated->imprintUrl)->toBe('https://example.com/imprint')
                    ->and($updated->appearance)->toBe(StatuspageAppearance::Dark)
                    ->and($updated->allowAppearanceSwitch)->toBeFalse()
                    ->and($cleared->primaryColor)->toBeNull()
                    ->and($cleared->imprintUrl)->toBeNull()
                    ->and($cleared->appearance)->toBe(StatuspageAppearance::System)
                    ->and($cleared->allowAppearanceSwitch)->toBeTrue()
                    ->and($client->statuspages()->get($page->id)->isPublished)->toBeTrue();
            });

            $journey->step('7 a generated PNG as logo and favicon, then removed; a non-image is refused', function () use ($client, $customers): void {
                $page = $customers['b']['page'];
                $logo = DtoAudit::inspect($client->statuspages()->uploadAsset($page->id, AssetType::Logo, AssetFile::fromContents(Scenario::png(), 'logo.png')), 'statuspages.uploadAsset');
                $favicon = $client->statuspages()->uploadAsset($page->id, AssetType::Favicon, AssetFile::fromContents(Scenario::png(32), 'favicon.png'));

                expect($logo->logoUrl)->not->toBeNull()
                    ->and($logo->components)->toBeNull()
                    ->and($favicon->faviconUrl)->not->toBeNull()
                    ->and($client->statuspages()->get($page->id)->logoUrl)->toBe($logo->logoUrl);

                $noLogo = DtoAudit::inspect($client->statuspages()->deleteAsset($page->id, AssetType::Logo), 'statuspages.deleteAsset');
                $noFavicon = $client->statuspages()->deleteAsset($page->id, AssetType::Favicon);

                expect($noLogo->logoUrl)->toBeNull()
                    ->and($noFavicon->faviconUrl)->toBeNull()
                    ->and($client->statuspages()->deleteAsset($page->id, AssetType::Logo)->logoUrl)->toBeNull();

                try {
                    $client->statuspages()->uploadAsset($page->id, AssetType::Logo, AssetFile::fromContents('not an image', 'logo.png'));
                    expect(false)->toBeTrue('A text file was accepted as a logo.');
                } catch (ValidationException $e) {
                    expect($e->hasError('file'))->toBeTrue();
                }
            });

            $journey->step('8 custom domain: attach with its records, verify twice (the cooldown is waited out), detach', function () use ($client, $customers, $journey): void {
                $page = $customers['a']['page'];
                $domains = $client->statuspages()->customDomains($page->id);
                $hostname = Scenario::run() . '.example.com';
                $domain = DtoAudit::inspect($domains->attach($hostname), 'customDomains.attach');
                $records = $domain->dnsRecords();

                expect($domain->hostname)->toBe($hostname)
                    ->and($domain->status)->toBe(CustomDomainStatus::PendingVerification)
                    ->and($domain->isActive())->toBeFalse()
                    ->and($domain->verified)->toBeFalse()
                    ->and($domain->verifiedAt)->toBeNull()
                    ->and($domain->lastErrorCode)->toBeNull()
                    ->and($domain->cnameTarget)->not->toBe('')
                    ->and($domain->txtRecordName)->toContain($hostname)
                    ->and($domain->txtRecordValue)->not->toBeNull()
                    ->and($domain->statuspageId)->toBe($page->id)
                    ->and($records)->toHaveCount(2)
                    ->and($records[0]->isCname())->toBeTrue()
                    ->and($records[0]->name)->toBe($hostname)
                    ->and($records[0]->value)->toBe($domain->cnameTarget)
                    ->and($records[1]->isTxt())->toBeTrue()
                    ->and($records[1]->value)->toBe($domain->txtRecordValue);

                $listed = DtoAudit::inspect($domains->all(), 'customDomains.all');

                expect(array_map(static fn(CustomDomain $candidate): string => $candidate->id, $listed))->toContain($domain->id)
                    ->and(DtoAudit::inspect($domains->get($domain->id), 'customDomains.get')->hostname)->toBe($hostname);

                // A malformed hostname is refused on `hostname` before anything else.
                try {
                    $domains->attach('not a hostname');
                    expect(false)->toBeTrue('A malformed hostname was attached.');
                } catch (ValidationException $e) {
                    expect($e->hasError('hostname'))->toBeTrue();
                }

                // The plan caps the domains per page; once reached, the next attach is a plan limit with the numbers.
                try {
                    $domains->attach(Scenario::run() . '-second.example.com');
                    $journey->note('the plan allows more than one domain per page; the limit was not reached');
                } catch (PlanLimitException $e) {
                    $journey->note(sprintf('custom domains per page: limit %d, usage %d', $e->limit() ?? -1, $e->usage() ?? -1));

                    expect($e->limitKey())->toBe('custom_domains')
                        ->and($e->limit())->not->toBeNull()
                        ->and($e->usage())->toBe($e->limit())
                        ->and($e->status())->toBe(403);
                }

                // The same hostname cannot be attached anywhere else, not even on another page.
                try {
                    $client->statuspages()->customDomains($customers['b']['page']->id)->attach($hostname);
                    expect(false)->toBeTrue('The hostname was attached to a second page.');
                } catch (ValidationException $e) {
                    expect($e->hasError('hostname'))->toBeTrue();
                }

                $first = DtoAudit::inspect($domains->verify($domain->id), 'customDomains.verify');

                expect($first->status)->toBe(CustomDomainStatus::PendingVerification)
                    ->and($first->lastErrorCode)->toBeInstanceOf(CustomDomainErrorCode::class);

                $mark = LiveApi::sleeper()->count();
                $started = hrtime(true);
                $second = $domains->verify($domain->id);
                $seconds = (hrtime(true) - $started) / 1e9;
                $waits = LiveApi::sleeper()->since($mark);
                $waited = (float) array_sum($waits);

                $journey->note(sprintf('first verify: %s; second verify took %.1f s, the SDK waited %.1f s for Retry-After', $first->lastErrorCode->value ?? 'no error code', $seconds, $waited));

                expect($waits)->not->toBe([], 'The second verify was not throttled by the per-domain cooldown.')
                    ->and($waited)->toBeGreaterThan(0.0)
                    ->and($waited)->toBeLessThanOrEqual(11.0)
                    ->and($seconds)->toBeGreaterThanOrEqual($waited)
                    ->and($second->status)->toBe(CustomDomainStatus::PendingVerification);

                $domains->detach($domain->id);

                expect(static fn(): CustomDomain => $domains->get($domain->id))->toThrow(NotFoundException::class)
                    ->and(array_map(static fn(CustomDomain $candidate): string => $candidate->id, $domains->all()))->not->toContain($domain->id);
            });

            $journey->step('9 a tag with a synced group cannot be deleted; page first, then tag', function () use ($client, $customers): void {
                $tag = $customers['b']['tag'];
                $page = $customers['b']['page'];

                try {
                    $client->tags()->delete($tag->id);
                    expect(false)->toBeTrue('A tag a synced group references was deleted.');
                } catch (ValidationException $e) {
                    expect($e->status())->toBe(422)
                        ->and($e->hasError('tag'))->toBeTrue();
                }

                expect($client->tags()->get($tag->id)->id)->toBe($tag->id);

                $client->statuspages()->delete($page->id);

                expect(static fn(): Statuspage => $client->statuspages()->get($page->id))->toThrow(NotFoundException::class)
                    ->and($client->statuspages()->findBySlug($page->slug))->toBeNull();

                // The services still carry the tag; the tag can go once nothing syncs on it.
                foreach ($customers['b']['services'] as $service) {
                    $client->services()->update($service->id, UpdateService::make()->withoutTags());
                }

                $client->tags()->delete($tag->id);

                expect(static fn(): Tag => $client->tags()->get($tag->id))->toThrow(NotFoundException::class);
            });
        } finally {
            $cleanupFailures = $journey->step('10 cleanup: pages and services, then tags', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
