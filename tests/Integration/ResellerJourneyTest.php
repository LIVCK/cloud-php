<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\ComponentBuilder;
use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\CustomDomain;
use LIVCK\Cloud\Data\Probe;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\Statuspage;
use LIVCK\Cloud\Data\StatuspageComponent;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\CustomDomainStatus;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Payloads\CreateStatuspage;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Query\CheckQuery;
use LIVCK\Cloud\Query\IncidentQuery;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Query\ServiceIncidentQuery;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Support\KeepSecret;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;

/**
 * One reseller's journey through the SDK against a live API: opt-in, see LiveApi. Every
 * object it creates carries the run prefix and is removed at the end, whatever happened.
 */
$skip = LiveApi::skipReason();

describe('live API', function () use ($skip): void {
    it('answers the discovery calls for the token', function (): void {
        $client = LiveApi::client();
        $me = $client->me();

        expect($me->permissions)->toContain('services.view')
            ->and($me->organization->publicId)->not->toBe('')
            ->and($client->probes())->not->toBeEmpty()
            ->and($client->checkTypes()->has(CheckType::Http))->toBeTrue();
    })->skip($skip !== null, $skip ?? '');

    it('runs the reseller journey end to end', function (): void {
        $client = LiveApi::client();
        $prefix = LiveApi::runPrefix();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $journey->note(sprintf('base URI %s, run prefix %s', LiveApi::baseUri(), $prefix));
        $cleanupFailures = [];

        try {
            /** @var array{0: list<string>, 1: CheckTypeCatalog} $discovery */
            $discovery = $journey->step('1 me, probes, check types', function () use ($client): array {
                $me = $client->me();

                expect($me->permissions)->toContain('services.create')->toContain('statuspages.create');

                $probes = $client->probes();
                $catalog = $client->checkTypes();

                expect($probes)->not->toBeEmpty()
                    ->and($catalog->has(CheckType::Http))->toBeTrue()
                    ->and($catalog->has(CheckType::Ssl))->toBeTrue();

                return [array_map(static fn(Probe $probe): string => $probe->code, $probes), $catalog];
            });
            [$probeCodes, $catalog] = $discovery;

            $tag = $journey->step('2 tag ensure: created, then found', function () use ($client, $prefix, $cleanup): Tag {
                $first = $client->tags()->ensure($prefix, 'customer-4711', '#6366f1');
                $cleanup->add('tag ' . $first->tag->id, static fn() => $client->tags()->delete($first->tag->id));

                expect($first->wasCreated())->toBeTrue()
                    ->and($first->tag->label)->toBe($prefix . ':customer-4711');

                $second = $client->tags()->ensure($prefix, 'customer-4711');

                expect($second->existed())->toBeTrue()
                    ->and($second->tag->id)->toBe($first->tag->id);

                return $first->tag;
            });

            /** @var array{0: Statuspage, 1: StatuspageComponent} $pageAndGroup */
            $pageAndGroup = $journey->step('3 status page with a group synced to the tag', function () use ($client, $prefix, $tag, $cleanup): array {
                $page = $client->statuspages()->create(CreateStatuspage::make('Status ' . $prefix)->withSlug($prefix));
                $cleanup->add('status page ' . $page->id, static fn() => $client->statuspages()->delete($page->id));

                expect($page->slug)->toBe($prefix);

                $group = $client->statuspages()->components($page->id)->create(ComponentBuilder::syncedGroup('Your services', $tag)->syncNewVisible());

                expect($group->isGroup)->toBeTrue()
                    ->and($group->syncTagId)->toBe($tag->id);

                return [$page, $group];
            });
            [$page, $group] = $pageAndGroup;

            /** @var array{0: Service, 1: Service} $services */
            $services = $journey->step('4 HTTP and SSL services, idempotent create', function () use ($client, $prefix, $tag, $probeCodes, $catalog, $cleanup): array {
                $locations = array_slice($probeCodes, 0, 2);
                $http = ServiceBuilder::http($prefix . '-web', 'https://example.com/')
                    ->interval(60)
                    ->probes(...$locations)
                    ->tags($tag)
                    ->header('X-Api-Key', 'secret-one')
                    ->auth(HttpAuth::bearer('secret-two'))
                    ->condition(HttpCondition::statusCode()->gte(500), HttpCondition::responseTimeMs()->gt(5000)->degraded());
                $http->validate($catalog);

                $key = $prefix . '-web-create';
                $first = $client->send(Request::post('services', $http->toArray())->withIdempotencyKey($key));
                $created = Service::fromArray(LiveApi::data($first));
                $cleanup->add('service ' . $created->id, static fn() => $client->services()->delete($created->id));

                expect($first->status())->toBe(201)
                    ->and($first->wasReplayed())->toBeFalse()
                    ->and($created->tagIds())->toContain($tag->id);

                $replay = $client->send(Request::post('services', $http->toArray())->withIdempotencyKey($key));

                expect($replay->wasReplayed())->toBeTrue()
                    ->and(LiveApi::data($replay)['id'] ?? null)->toBe($created->id);

                $ssl = $client->services()->create(ServiceBuilder::ssl($prefix . '-cert', 'example.com')->probes(...$locations)->tags($tag), $catalog);
                $cleanup->add('service ' . $ssl->id, static fn() => $client->services()->delete($ssl->id));

                expect($ssl->checkType)->toBe(CheckType::Ssl)
                    ->and($ssl->settings?->intervalSeconds)->toBe(21600);

                return [$created, $ssl];
            });
            [$http, $ssl] = $services;

            $journey->step('5 synced children appear (polled, at most 30 s)', function () use ($client, $page, $group, $http, $ssl, $journey): void {
                $wanted = [$http->id, $ssl->id];
                $started = hrtime(true);
                $found = [];

                do {
                    $children = array_filter(
                        $client->statuspages()->components($page->id)->all(),
                        static fn(StatuspageComponent $component): bool => $component->parentId === $group->id && $component->isSyncManaged,
                    );
                    $found = array_values(array_filter(
                        array_map(static fn(StatuspageComponent $component): ?string => $component->service?->id, $children),
                        static fn(?string $id): bool => $id !== null,
                    ));

                    if (array_intersect($wanted, $found) === $wanted) {
                        break;
                    }

                    usleep(1_000_000);
                } while ((hrtime(true) - $started) < 30 * 1e9);

                $journey->note(sprintf('sync wait %.1f s', (hrtime(true) - $started) / 1e9));

                expect(array_values(array_intersect($wanted, $found)))->toEqualCanonicalizing($wanted, 'The synced group did not receive both services within 30 s.');
            });

            $journey->step('6 list by tag; checks, incidents, maintenances', function () use ($client, $tag, $http, $ssl): void {
                $listed = $client->services()->list(ServiceQuery::make()->withTag($tag)->withPerPage(50));
                $ids = array_map(static fn(Service $service): string => $service->id, $listed->items);

                expect($ids)->toContain($http->id)->toContain($ssl->id);

                foreach ([$http, $ssl] as $service) {
                    $checks = $client->services()->checks($service->id, CheckQuery::make()->withPerPage(5));

                    expect($checks->perPage)->toBe(5);

                    $incidents = $client->services()->incidents($service->id, ServiceIncidentQuery::make()->withResolved(false));
                    $maintenances = $client->services()->maintenances($service->id, MaintenanceQuery::make()->withStatuses(MaintenanceStatus::Scheduled));

                    expect($incidents->items)->toBeArray()
                        ->and($maintenances->items)->toBeArray();
                }

                $incidents = $client->incidents()->list(IncidentQuery::make()->withServiceIds([$http, $ssl]));
                $maintenances = $client->maintenances()->list(MaintenanceQuery::make()->withServiceIds([$http, $ssl]));

                expect($incidents->total)->toBeGreaterThanOrEqual(0)
                    ->and($maintenances->total)->toBeGreaterThanOrEqual(0);
            });

            $journey->step('7 basedOn() update adds a header, keeps the other secrets', function () use ($client, $http): void {
                $current = $client->services()->get($http->id);
                $updated = $client->services()->update($http->id, UpdateService::basedOn($current)->withHeader('X-Trace', 'sdkit'));
                $headers = $updated->settings?->config['headers'] ?? null;
                $auth = $updated->settings?->config['auth'] ?? null;

                expect($headers)->toBeArray()->toHaveKeys(['X-Api-Key', 'X-Trace'])
                    ->and(KeepSecret::isSentinel(is_array($headers) ? ($headers['X-Api-Key'] ?? null) : null))->toBeTrue()
                    ->and(is_array($auth) ? ($auth['type'] ?? null) : null)->toBe('bearer')
                    ->and($updated->settings?->intervalSeconds)->toBe($current->settings?->intervalSeconds);
            });

            $journey->step('8 pause and resume', function () use ($client, $http): void {
                expect($client->services()->pause($http->id)->isPaused)->toBeTrue()
                    ->and($client->services()->resume($http->id)->isPaused)->toBeFalse();
            });

            $journey->step('9 custom domain attach (DNS records) and detach', function () use ($client, $page, $prefix): void {
                $domains = $client->statuspages()->customDomains($page->id);
                $hostname = $prefix . '.sdk-integration.example';
                $domain = $domains->attach($hostname);

                expect($domain->hostname)->toBe($hostname)
                    ->and($domain->status)->toBe(CustomDomainStatus::PendingVerification)
                    ->and($domain->cnameTarget)->not->toBe('')
                    ->and($domain->txtRecordName)->toContain($hostname)
                    ->and($domain->dnsRecords())->toHaveCount(2);

                $domains->detach($domain->id);

                $live = array_filter(
                    $domains->all(),
                    static fn(CustomDomain $candidate): bool => $candidate->id === $domain->id
                        && ! in_array($candidate->status, [CustomDomainStatus::Removing, CustomDomainStatus::Removed], true),
                );

                expect($live)->toBeEmpty('The detached domain is still listed as live.');
            });
        } finally {
            $cleanupFailures = $journey->step('10 cleanup: services, status page, tag', static fn(): array => $cleanup->run());
            fwrite(STDERR, "\n" . $journey->report() . "\n");
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
