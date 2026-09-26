<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\ConditionRule;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Enums\ConditionOutcome;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Enums\ServiceStatus;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Support\KeepSecret;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 5, updates: basedOn() changes one header and the interval while every stored
 * secret stays (read back masked, and the check still passes afterwards), then rename and
 * retarget, tags replaced, cleared and moved between customers, partial settings, probes
 * and roles, conditions replaced and reset, the auth block replaced, and the client-side
 * refusals.
 */
$skip = LiveApi::skipReason();

describe('scenario 5: updates', function () use ($skip): void {
    it('updates a service part by part and keeps what it did not touch', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $cleanupFailures = [];

        try {
            $tagA = Scenario::customerTag($client, $cleanup, 'a');
            $tagB = Scenario::customerTag($client, $cleanup, 'b');

            $service = $journey->step('1 an HTTP service with two headers and a bearer token', function () use ($client, $cleanup, $tagA): Service {
                $service = Scenario::service($client, $cleanup, ServiceBuilder::http(Scenario::name('upd-http'), 'https://example.com/')
                    ->interval(30)
                    ->probes('ffm', 'hel', 'nbg')
                    ->header('X-Api-Key', 'secret-a')
                    ->header('X-Trace', 'one')
                    ->auth(HttpAuth::bearer('secret-b'))
                    ->tags($tagA));

                expect($service->settings?->headers())->toHaveKeys(['X-Api-Key', 'X-Trace'])
                    ->and($service->settings?->intervalSeconds)->toBe(30);

                return $service;
            });
            $id = $service->id;

            $journey->step('2 basedOn(): a new interval and one header, the other secrets kept', function () use ($client, $id): void {
                $current = $client->services()->get($id);
                $updated = DtoAudit::inspect($client->services()->update($id, UpdateService::basedOn($current)->withIntervalSeconds(60)->withHeader('X-Trace', 'two')), 'services.update (basedOn)');
                $fresh = $client->services()->get($id);

                foreach ([$updated, $fresh] as $service) {
                    $headers = $service->settings?->headers() ?? [];
                    $auth = $service->settings?->value('auth');

                    expect($service->settings?->intervalSeconds)->toBe(60)
                        ->and($service->settings?->timeoutSeconds)->toBe($current->settings?->timeoutSeconds)
                        ->and($service->settings?->retries)->toBe($current->settings?->retries)
                        ->and($service->settings?->assignedProbes)->toBe(['ffm', 'hel', 'nbg'])
                        ->and(array_keys($headers))->toEqualCanonicalizing(['X-Api-Key', 'X-Trace'])
                        ->and(KeepSecret::isSentinel($headers['X-Api-Key'] ?? null))->toBeTrue()
                        ->and(KeepSecret::isSentinel($headers['X-Trace'] ?? null))->toBeTrue()
                        ->and(is_array($auth) ? ($auth['type'] ?? null) : null)->toBe('bearer')
                        ->and(KeepSecret::isSentinel(is_array($auth) ? ($auth['token'] ?? null) : null))->toBeTrue()
                        ->and($service->settings?->conditions())->toHaveCount(count($current->settings?->conditions() ?? []));
                }
            });

            $journey->step('3 the kept secrets still work: the check passes (polled, at most 180 s)', function () use ($client, $id, $journey): void {
                [$status, $elapsed] = Scenario::waitFor(180, static function () use ($client, $id): ?ServiceStatus {
                    $service = $client->services()->get($id);

                    return !$service->lastCheckAt instanceof DateTimeImmutable || $service->status === ServiceStatus::Unknown ? null : $service->status;
                }, 5.0);

                $journey->note(sprintf('first verdict after %.0f s: %s', $elapsed, $status instanceof ServiceStatus ? $status->value : 'none'));

                expect($status)->toBeInstanceOf(ServiceStatus::class, 'The service was not checked within 180 s.')
                    ->and($status)->not->toBe(ServiceStatus::Down);
            });

            $journey->step('4 rename and retarget', function () use ($client, $id): void {
                $updated = DtoAudit::inspect($client->services()->update($id, UpdateService::make()->withName(Scenario::name('upd-renamed'))->withTarget('https://www.example.com/')), 'services.update (name, target)');
                $fresh = $client->services()->get($id);

                expect($updated->name)->toBe(Scenario::name('upd-renamed'))
                    ->and($updated->target)->toBe('https://www.example.com/')
                    ->and($fresh->name)->toBe(Scenario::name('upd-renamed'))
                    ->and($fresh->target)->toBe('https://www.example.com/')
                    ->and($fresh->settings?->intervalSeconds)->toBe(60)
                    ->and(array_keys($fresh->settings?->headers() ?? []))->toEqualCanonicalizing(['X-Api-Key', 'X-Trace']);
            });

            $journey->step('5 tags: replace, clear, and move from customer A to customer B', function () use ($client, $id, $tagA, $tagB): void {
                $both = $client->services()->update($id, UpdateService::make()->withTags($tagA, $tagB));
                $none = $client->services()->update($id, UpdateService::make()->withoutTags());
                $onlyA = $client->services()->update($id, UpdateService::make()->withTags($tagA->id));
                $moved = $client->services()->update($id, UpdateService::make()->withTags($tagB));

                expect($both->tagIds())->toEqualCanonicalizing([$tagA->id, $tagB->id])
                    ->and($none->tagIds())->toBe([])
                    ->and($onlyA->tagIds())->toBe([$tagA->id])
                    ->and($moved->tagIds())->toBe([$tagB->id])
                    ->and($moved->hasTag($tagB->label))->toBeTrue()
                    ->and($moved->hasTag($tagA->label))->toBeFalse()
                    ->and($client->services()->get($id)->tagIds())->toBe([$tagB->id])
                    ->and($client->tags()->get($tagB->id)->servicesCount)->toBe(1)
                    ->and($client->tags()->get($tagA->id)->servicesCount)->toBe(0);
            });

            $journey->step('6 a partial settings block without an interval is accepted', function () use ($client, $id): void {
                $updated = DtoAudit::inspect($client->services()->update($id, UpdateService::make()->withTimeoutSeconds(20)->withRetries(1)), 'services.update (partial settings)');

                expect($updated->settings?->timeoutSeconds)->toBe(20)
                    ->and($updated->settings?->retries)->toBe(1)
                    ->and($updated->settings?->intervalSeconds)->toBe(60)
                    ->and(array_keys($updated->settings?->headers() ?? []))->toEqualCanonicalizing(['X-Api-Key', 'X-Trace'])
                    ->and($updated->settings?->value('method'))->toBe('GET');

                $method = $client->services()->update($id, UpdateService::make()->withMethod(HttpMethod::Head)->withFollowRedirects(false)->withVerifySsl(false));

                expect($method->settings?->value('method'))->toBe('HEAD')
                    ->and($method->settings?->value('follow_redirects'))->toBeFalse()
                    ->and($method->settings?->value('verify_ssl'))->toBeFalse()
                    ->and($method->settings?->timeoutSeconds)->toBe(20);
            });

            $journey->step('7 locations and roles: the whole set each time', function () use ($client, $id): void {
                $two = $client->services()->update($id, UpdateService::make()->withProbes('ffm', 'hel')->withProbeRoles(['hel' => ProbeRole::Reachability]));
                $noRoles = $client->services()->update($id, UpdateService::make()->withoutProbeRoles());

                expect($two->settings?->assignedProbes)->toBe(['ffm', 'hel'])
                    ->and($two->settings?->probeRoles)->toBe(['hel' => ProbeRole::Reachability])
                    ->and($noRoles->settings?->probeRoles)->toBeNull()
                    ->and($noRoles->settings?->assignedProbes)->toBe(['ffm', 'hel']);
            });

            $journey->step('8 conditions: replaced as a whole, then reset to the defaults', function () use ($client, $id): void {
                $replaced = $client->services()->update($id, UpdateService::make()->withConditions(
                    HttpCondition::statusCode()->gte(500),
                    HttpCondition::responseTimeMs()->gt(9000)->degraded(),
                ));
                $conditions = $replaced->settings?->conditions() ?? [];

                expect($conditions)->toHaveCount(2)
                    ->and($conditions[0]->field)->toBe('status_code')
                    ->and($conditions[0]->operator)->toBe(ConditionOperator::Gte)
                    ->and($conditions[0]->value)->toBe(500)
                    ->and($conditions[0]->outcome)->toBe(ConditionOutcome::Down)
                    ->and($conditions[1]->field)->toBe('response_time_ms')
                    ->and($conditions[1]->outcome)->toBe(ConditionOutcome::Degraded);

                $defaults = $client->services()->update($id, UpdateService::make()->withDefaultConditions());
                $seeded = array_map(static fn(ConditionRule $rule): array => [$rule->field, $rule->operator->value, $rule->value, $rule->outcome->value], $defaults->settings?->conditions() ?? []);

                expect($seeded)->toBe([['status_code', 'gte', 400, 'down']]);
            });

            $journey->step('9 auth and headers replaced as whole blocks', function () use ($client, $id): void {
                $basic = $client->services()->update($id, UpdateService::make()->withAuth(HttpAuth::basic('monitor', 'secret-c'))->withHeaders(['X-Only' => 'one']));
                $auth = $basic->settings?->value('auth');

                expect(is_array($auth) ? ($auth['type'] ?? null) : null)->toBe('basic')
                    ->and(is_array($auth) ? ($auth['username'] ?? null) : null)->toBe('monitor')
                    ->and(KeepSecret::isSentinel(is_array($auth) ? ($auth['password'] ?? null) : null))->toBeTrue()
                    ->and(array_keys($basic->settings?->headers() ?? []))->toBe(['X-Only']);

                $none = $client->services()->update($id, UpdateService::make()->withAuth(HttpAuth::none())->withHeaders([]));
                $noAuth = $none->settings?->value('auth');

                expect(is_array($noAuth) ? ($noAuth['type'] ?? null) : null)->toBe('none')
                    ->and($none->settings?->headers())->toBe([]);
            });

            $journey->step('10 client-side refusals send nothing', function () use ($client, $id): void {
                $sent = LiveApi::attempts()->attempts();

                expect(static fn(): Service => $client->services()->update($id, UpdateService::make()))->toThrow(InvalidArgumentException::class);
                expect(static fn(): UpdateService => UpdateService::make()->withName(' '))->toThrow(InvalidArgumentException::class);
                expect(static fn(): UpdateService => UpdateService::make()->withTarget(''))->toThrow(InvalidArgumentException::class);
                expect(static fn(): UpdateService => UpdateService::make()->withTags('customer:4711'))->toThrow(InvalidArgumentException::class);
                expect(static fn(): UpdateService => UpdateService::make()->withIntervalSeconds(0))->toThrow(InvalidArgumentException::class);
                expect(static fn(): UpdateService => UpdateService::make()->withProbes())->toThrow(InvalidArgumentException::class);
                expect(static fn(): UpdateService => UpdateService::make()->withHeader('', 'x'))->toThrow(InvalidArgumentException::class);
                expect(static fn(): UpdateService => UpdateService::basedOn(Service::fromArray(['id' => 'x', 'name' => 'x', 'check_type' => 'manual', 'check_type_label' => 'Manual', 'target' => null, 'status' => 'unknown', 'effective_status' => 'operational', 'is_paused' => false, 'is_configured' => false, 'created_at' => '2026-01-01T00:00:00+00:00'])))->toThrow(InvalidArgumentException::class);
                expect(LiveApi::attempts()->attempts())->toBe($sent);
            });
        } finally {
            $cleanupFailures = $journey->step('11 cleanup: the service, then the tags', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
