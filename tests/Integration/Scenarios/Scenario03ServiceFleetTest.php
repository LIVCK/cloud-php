<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\Conditions\DnsCondition;
use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\Conditions\IcmpCondition;
use LIVCK\Cloud\Builders\Conditions\SslCondition;
use LIVCK\Cloud\Builders\Conditions\TcpCondition;
use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Builders\MonitoredServiceBuilder;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Builders\SslServiceBuilder;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\ConditionRule;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\ServiceSettings;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\DnsRecordType;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\IpVersion;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Enums\ServiceStatus;
use LIVCK\Cloud\Query\IncidentQuery;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Support\Json;
use LIVCK\Cloud\Support\KeepSecret;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 3, a fleet in one go: 20 services across every creatable check type, with every
 * builder option in use, spread over three end customers. Every service is read back and
 * compared with what was sent, secrets included (they come back masked); the fleet is then
 * left to the probes until every service has been checked, and none may go down.
 */
$skip = LiveApi::skipReason();

describe('scenario 3: twenty services across all check types', function () use ($skip): void {
    it('creates the fleet, reads every service back and sees it checked', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $cleanupFailures = [];
        $probes = ['ffm', 'hel', 'nbg'];
        $journey->note(sprintf('run %s, locations %s', Scenario::run(), implode('/', $probes)));

        try {
            $catalog = $journey->step('1 the catalog and three customer tags', static fn(): CheckTypeCatalog => DtoAudit::inspect($client->checkTypes(), 'checkTypes'));
            $tags = [
                'a' => Scenario::customerTag($client, $cleanup, 'a', '#6366f1'),
                'b' => Scenario::customerTag($client, $cleanup, 'b', '#22c55e'),
                'c' => Scenario::customerTag($client, $cleanup, 'c', '#f59e0b'),
            ];

            $fleet = $journey->step('2 twenty builders, every option, validated against the catalog', function () use ($catalog, $tags, $probes): array {
                $name = static fn(string $suffix): string => Scenario::name($suffix);
                $specs = [
                    // 8 × HTTP: method, headers, bearer / basic / api-key auth, body, redirects off,
                    // verify-ssl off, ipVersion; conditions on status code, response time, body, JSON path.
                    [ServiceBuilder::http($name('http-get'), 'https://example.com/')
                        ->timeout(15)->retries(1)
                        ->header('X-Trace', Scenario::run())->header('X-Api-Key', 'secret-1')
                        ->condition(HttpCondition::statusCode()->gte(500)->down(), HttpCondition::responseTimeMs()->gt(20000)->degraded()), ['a']],
                    [ServiceBuilder::http($name('http-head'), 'https://example.com/')
                        ->method(HttpMethod::Head)->auth(HttpAuth::none()), ['a']],
                    [ServiceBuilder::http($name('http-bearer'), 'https://example.com/')
                        ->auth(HttpAuth::bearer('secret-2'))
                        ->condition(HttpCondition::body()->notContains('Example Domain')->down()), ['a', 'b']],
                    [ServiceBuilder::http($name('http-basic'), 'https://example.com/')
                        ->auth(HttpAuth::basic('monitor', 'secret-3'))->verifySsl(false)
                        ->condition(HttpCondition::header('content-type')->notContains('text/html')->degraded()), ['b']],
                    [ServiceBuilder::http($name('http-apikey'), 'https://example.com/')
                        ->auth(HttpAuth::apiKey('X-Api-Key', 'secret-4'))->followRedirects(false)
                        ->condition(HttpCondition::redirectsFollowed()->gt(0)->degraded()), ['b']],
                    [ServiceBuilder::http($name('http-post-json'), 'https://jsonplaceholder.typicode.com/posts')
                        ->method(HttpMethod::Post)->body('{"title":"sdke2e"}')
                        ->headers(['Content-Type' => 'application/json', 'X-Trace' => Scenario::run()])
                        ->condition(HttpCondition::json('id')->lt(1)->down(), HttpCondition::statusCode()->notIn([200, 201])->down()), ['c']],
                    [ServiceBuilder::http($name('http-json'), 'https://jsonplaceholder.typicode.com/todos/1')
                        ->condition(
                            HttpCondition::json('id')->neq(1)->down(),
                            HttpCondition::json('title')->notContains('delectus')->degraded(),
                            HttpCondition::header('content-type')->notContains('json')->degraded(),
                            HttpCondition::contentLength()->lt(10)->down(),
                            HttpCondition::protocol()->contains('HTTP/0.9')->down(),
                        ), ['c']],
                    [ServiceBuilder::http($name('http-ipv4'), 'https://example.com/')
                        ->ipVersion(IpVersion::Ipv4)->smartDualstack(false)->probeRole('hel', ProbeRole::Reachability)
                        ->condition(HttpCondition::finalUrl()->notContains('example.com')->degraded(), HttpCondition::statusCode()->in([500, 502, 503, 504])->down()), ['a', 'c']],
                    // 3 × TCP
                    [ServiceBuilder::tcp($name('tcp-dns'), '1.1.1.1', 53)
                        ->condition(TcpCondition::responseTimeMs()->gt(15000)->degraded()), ['a']],
                    [ServiceBuilder::tcp($name('tcp-https'), 'example.com', 443), ['b']],
                    [ServiceBuilder::tcp($name('tcp-quad9'), '9.9.9.9', 53)
                        ->ipVersion(IpVersion::Ipv4)
                        ->condition(TcpCondition::resolvedIpCount()->lt(1)->down()), ['c']],
                    // 3 × DNS, different record types
                    [ServiceBuilder::dns($name('dns-a'), 'example.com')
                        ->condition(DnsCondition::ipCount()->eq(0)->down(), DnsCondition::ips()->contains('0.0.0.0')->degraded()), ['a']],
                    [ServiceBuilder::dns($name('dns-aaaa'), 'example.com', DnsRecordType::Aaaa)
                        ->condition(DnsCondition::ipCount()->eq(0)->down()), ['b']],
                    [ServiceBuilder::dns($name('dns-ns'), 'example.com', DnsRecordType::Ns)
                        ->condition(DnsCondition::nsCount()->lt(1)->down(), DnsCondition::responseTimeMs()->gt(10000)->degraded()), ['c']],
                    // 2 × ICMP
                    [ServiceBuilder::icmp($name('icmp-cf'), 'one.one.one.one')
                        ->condition(IcmpCondition::packetLossPercent()->gte(100)->down()), ['a']],
                    [ServiceBuilder::icmp($name('icmp-quad9'), '9.9.9.9')
                        ->ipVersion(IpVersion::Ipv4)
                        ->condition(IcmpCondition::maxRttMs()->gt(10000)->degraded(), IcmpCondition::packetsReceived()->lt(1)->down()), ['b']],
                    // 2 × SSL (hourly at the fastest; the type's own default is six hours)
                    [ServiceBuilder::ssl($name('ssl-example'), 'example.com')
                        ->condition(SslCondition::daysUntilExpiry()->lt(3)->down()), ['c']],
                    [ServiceBuilder::ssl($name('ssl-cf'), 'cloudflare.com')
                        ->interval(3600)
                        ->condition(SslCondition::tlsVersion()->in(['TLS 1.0', 'TLS 1.1'])->degraded(), SslCondition::daysUntilExpiry()->lt(3)->down()), ['a']],
                    // 2 × manual
                    [ServiceBuilder::manual($name('manual-phone')), ['b']],
                    [ServiceBuilder::manual($name('manual-desk')), ['c']],
                ];

                $fleet = [];

                foreach ($specs as [$builder, $customers]) {
                    if ($builder instanceof SslServiceBuilder) {
                        $builder = $builder->probes(...$probes);
                    } elseif ($builder instanceof MonitoredServiceBuilder) {
                        $builder = $builder->interval(30)->probes(...$probes);
                    }

                    $builder = $builder->tags(...array_map(static fn(string $customer): Tag => $tags[$customer], $customers));
                    $builder->validate($catalog);
                    $fleet[] = ['builder' => $builder, 'customers' => $customers];
                }

                expect($fleet)->toHaveCount(20);

                return $fleet;
            });

            /** @var array<string, Service> $created */
            $created = $journey->step('3 create 20 services in one go', function () use ($client, $cleanup, $catalog, $fleet, $journey): array {
                $created = [];
                $started = hrtime(true);

                foreach ($fleet as ['builder' => $builder]) {
                    $service = DtoAudit::inspect($client->services()->create($builder, $catalog), 'services.create');
                    $cleanup->add('service ' . $service->name, static fn() => $client->services()->delete($service->id, deleteOrphanedIncidents: true, deleteOrphanedMaintenances: true));

                    expect($service->name)->toBe($builder->name())
                        ->and($service->checkType)->toBe($builder->checkType());

                    $created[$builder->name()] = $service;
                }

                $journey->note(sprintf('20 creates in %.1f s', (hrtime(true) - $started) / 1e9));

                return $created;
            });

            $journey->step('4 read-after-write: every field equals the builder input, secrets masked', function () use ($client, $catalog, $fleet, $tags, $created): void {
                foreach ($fleet as ['builder' => $builder, 'customers' => $customers]) {
                    $service = DtoAudit::inspect($client->services()->get($created[$builder->name()]->id), 'services.get');
                    $tagIds = array_map(static fn(string $customer): string => $tags[$customer]->id, $customers);
                    $sent = Json::decode(Json::encode($builder->toArray()));

                    expect($service->id)->toBe($created[$builder->name()]->id)
                        ->and($service->name)->toBe($builder->name())
                        ->and($service->checkType)->toBe($builder->checkType())
                        ->and($service->checkTypeLabel)->not->toBe('')
                        ->and($service->target)->toBe($builder->target())
                        ->and($service->tagIds())->toEqualCanonicalizing($tagIds)
                        ->and($service->hasTag($tags[$customers[0]]->label))->toBeTrue()
                        ->and($service->isPaused)->toBeFalse()
                        ->and($service->pausedReason)->toBeNull()
                        ->and($service->hasStatusOverride())->toBeFalse()
                        ->and($service->createdAt->getTimestamp())->toBeGreaterThan(time() - 900);

                    if ($builder->checkType() === CheckType::Manual) {
                        // Nothing to configure: a manual service is configured from the start, with no settings.
                        expect($service->settings)->toBeNull()
                            ->and($service->isConfigured)->toBeTrue()
                            ->and($service->configuredAt)->not->toBeNull()
                            ->and($service->isMonitoredByProbes())->toBeFalse()
                            ->and($service->effectiveStatus->isHealthy())->toBeTrue($service->effectiveStatus->value)
                            ->and(array_key_exists('settings', $sent))->toBeFalse();

                        continue;
                    }

                    $settings = $service->settings;
                    $sentSettings = $sent['settings'] ?? null;

                    expect($settings)->toBeInstanceOf(ServiceSettings::class)
                        ->and($sentSettings)->toBeArray()
                        ->and($service->isConfigured)->toBeTrue()
                        ->and($service->configuredAt)->not->toBeNull()
                        ->and($service->isMonitoredByProbes())->toBeTrue();

                    if (! $settings instanceof ServiceSettings || ! is_array($sentSettings)) {
                        continue;
                    }

                    $sentRoles = $sentSettings['probe_roles'] ?? null;
                    $storedRoles = $settings->probeRoles === null ? null : array_map(static fn(ProbeRole $role): string => $role->value, $settings->probeRoles);

                    expect($settings->intervalSeconds)->toBe($sentSettings['interval_seconds'] ?? null)
                        ->and($settings->timeoutSeconds)->toBe($sentSettings['timeout_seconds'] ?? 10)
                        ->and($settings->retries)->toBe($sentSettings['retries'] ?? 2)
                        ->and($settings->assignedProbes)->toBe($sentSettings['assigned_probes'] ?? null)
                        ->and($settings->inheritsProbes())->toBeFalse()
                        ->and($storedRoles)->toBe($sentRoles);

                    $sentConfig = $sentSettings['config'] ?? [];

                    expect($sentConfig)->toBeArray();

                    if (! is_array($sentConfig)) {
                        continue;
                    }

                    foreach ($sentConfig as $key => $value) {
                        $label = sprintf('%s: config.%s', $service->name, $key);

                        if ($key === 'conditions') {
                            continue;
                        }

                        if ($key === 'headers') {
                            $headers = $settings->headers();

                            expect(array_keys($headers))->toEqualCanonicalizing(array_keys(is_array($value) ? $value : []), $label);

                            foreach ($headers as $header => $stored) {
                                expect(KeepSecret::isSentinel($stored))->toBeTrue($label . '.' . $header);
                            }

                            continue;
                        }

                        if ($key === 'auth') {
                            $auth = $settings->value('auth');
                            $wanted = is_array($value) ? $value : [];

                            expect($auth)->toBeArray($label);

                            if (! is_array($auth)) {
                                continue;
                            }

                            expect($auth['type'] ?? null)->toBe($wanted['type'] ?? null, $label);

                            foreach (['username', 'header'] as $plain) {
                                if (array_key_exists($plain, $wanted)) {
                                    expect($auth[$plain] ?? null)->toBe($wanted[$plain], $label . '.' . $plain);
                                }
                            }

                            foreach (['token', 'password', 'value'] as $secret) {
                                if (array_key_exists($secret, $wanted)) {
                                    expect(KeepSecret::isSentinel($auth[$secret] ?? null))->toBeTrue($label . '.' . $secret);
                                }
                            }

                            continue;
                        }

                        expect($settings->value($key))->toBe($value, $label);
                    }

                    $rule = static fn(ConditionRule $rule): array => ['field' => $rule->field, 'operator' => $rule->operator->value, 'value' => $rule->value, 'status' => $rule->outcome->value];
                    $stored = array_map($rule, $settings->conditions());
                    $sentConditions = $sentConfig['conditions'] ?? null;

                    if (is_array($sentConditions) && $sentConditions !== []) {
                        expect($stored)->toEqualCanonicalizing($sentConditions, $service->name . ': conditions');
                    } else {
                        // Without conditions the server seeds the type's defaults, the same ones the catalog lists.
                        expect($stored)->toEqualCanonicalizing(array_map($rule, $catalog->type($service->checkType)->conditions->defaults), $service->name . ': default conditions');
                    }
                }
            });

            $journey->step('5 list by customer tag returns exactly each customer\'s services', function () use ($client, $fleet, $tags, $created, $journey): void {
                foreach ($tags as $customer => $tag) {
                    $wanted = [];

                    foreach ($fleet as ['builder' => $builder, 'customers' => $customers]) {
                        if (in_array($customer, $customers, true)) {
                            $wanted[] = $created[$builder->name()]->id;
                        }
                    }

                    $page = DtoAudit::inspect($client->services()->list(ServiceQuery::make()->withTag($tag)->withPerPage(100)), 'services.list (tag)');
                    $listed = array_map(static fn(Service $service): string => $service->id, $page->items);

                    expect($listed)->toEqualCanonicalizing($wanted, 'customer ' . $customer)
                        ->and($page->total)->toBe(count($wanted));

                    $journey->note(sprintf('customer %s: %d services', $customer, $page->total));
                }
            });

            $journey->step('6 every monitored service is checked and none goes down (polled, at most 240 s)', function () use ($client, $created, $journey): void {
                $monitored = array_filter($created, static fn(Service $service): bool => $service->isMonitoredByProbes());
                $ids = array_map(static fn(Service $service): string => $service->id, array_values($created));
                $snapshot = [];

                [$complete, $elapsed] = Scenario::waitFor(240, function () use ($client, $monitored, &$snapshot): ?bool {
                    $snapshot = [];

                    foreach ($client->services()->each(ServiceQuery::make()->withPerPage(100)) as $service) {
                        if (isset($monitored[$service->name])) {
                            $snapshot[$service->name] = DtoAudit::inspect($service, 'services.each');
                        }
                    }

                    foreach ($monitored as $name => $service) {
                        $latest = $snapshot[$name] ?? null;

                        if (! $latest instanceof Service || !$latest->lastCheckAt instanceof DateTimeImmutable || $latest->status === ServiceStatus::Unknown) {
                            return null;
                        }
                    }

                    return true;
                }, 10.0);

                $down = [];
                $unchecked = [];
                $statuses = [];

                foreach ($monitored as $name => $service) {
                    $latest = $snapshot[$name] ?? null;

                    if (! $latest instanceof Service || !$latest->lastCheckAt instanceof DateTimeImmutable) {
                        $unchecked[] = $name;

                        continue;
                    }

                    $statuses[$latest->status->value] = ($statuses[$latest->status->value] ?? 0) + 1;

                    if ($latest->status === ServiceStatus::Down) {
                        $down[] = sprintf('%s (%s %s)', $name, $latest->checkType->value, $latest->target ?? '');
                    }
                }

                $journey->note(sprintf(
                    '%s after %.0f s: %s%s',
                    $complete === true ? 'all checked' : 'timed out',
                    $elapsed,
                    implode(', ', array_map(static fn(string $status, int $count): string => sprintf('%s: %d', $status, $count), array_keys($statuses), $statuses)),
                    $unchecked === [] ? '' : '; not yet checked: ' . implode(', ', $unchecked),
                ));

                $incidents = DtoAudit::inspect($client->incidents()->list(IncidentQuery::make()->withServiceIds($ids)), 'incidents.list');

                expect($down)->toBe([], 'Healthy targets reported down: ' . implode(', ', $down))
                    ->and($incidents->total)->toBe(0, 'An incident was opened for the fleet.');

                // The SSL checks run hourly at the fastest, so they may not have been scheduled yet; anything else must have been.
                $notSsl = array_values(array_filter($unchecked, static fn(string $name): bool => ! str_contains($name, '-ssl-')));

                expect($notSsl)->toBe([], 'Never checked within 240 s: ' . implode(', ', $notSsl));
            });
        } finally {
            $cleanupFailures = $journey->step('7 cleanup: services, then tags', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
