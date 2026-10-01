<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\EnrollmentKeyBuilder;
use LIVCK\Cloud\Data\AgentMetrics;
use LIVCK\Cloud\Data\AgentMetricsHistory;
use LIVCK\Cloud\Data\CreatedEnrollmentKey;
use LIVCK\Cloud\Data\EnrollmentKey;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\AgentMetricsRange;
use LIVCK\Cloud\Enums\AgentState;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\EnrollmentKeyStatus;
use LIVCK\Cloud\Enums\EnrollmentKeyType;
use LIVCK\Cloud\Enums\TagSource;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Query\EnrollmentKeyQuery;
use LIVCK\Cloud\Query\TagQuery;
use LIVCK\Cloud\Support\Uuid;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 10, enrollment keys: a reseller's key for one server of a customer, with the
 * customer's tag. The installer is played through the escape hatch: a client whose bearer token
 * is the enrollment key posts to `/v1/agents/enroll`, asking for a tag under the key's own tag
 * key (which the key's tag must win) and one other tag. Then the key is exhausted and names the
 * server, the server carries the key's tag, its agent block names the key, and its figures
 * answer in shape (no agent reports on the stack, so nothing is asserted about their values)
 * while a service that is no agent service has none. Keys are revoked, twice. The server,
 * the services and every tag the run made are removed at the end; the system tags the agent's
 * host facts produce (`os`, `distro`, `arch`, `virt`, `agent`) cannot be deleted through the API
 * and are left to the server's clean-up of unused system tags.
 */
$skip = LiveApi::skipReason();

describe('scenario 10: enrollment keys and server agents', function () use ($skip): void {
    it('enrolls a server with a key that carries the customer tag, and reads its figures', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $run = Scenario::run();
        $hostname = Scenario::name('srv');
        $override = "{$run}:customer-intruder";
        $extra = "{$run}-role:web";
        $cleanupFailures = [];

        /** @var list<Tag> $madeByTheEnrollment user tags the install created, removed after the server */
        $madeByTheEnrollment = [];
        $cleanup->add('tags the enrollment created', static function () use ($client, &$madeByTheEnrollment): void {
            foreach ($madeByTheEnrollment as $tag) {
                $client->tags()->delete($tag->id);
            }
        });

        try {
            $tag = $journey->step('1 the customer tag', static fn(): Tag => Scenario::customerTag($client, $cleanup, 'servers'));

            $created = $journey->step('2 a single key with the customer tag; the key itself only in this answer', function () use ($client, $cleanup, $tag): CreatedEnrollmentKey {
                $created = $client->enrollmentKeys()->create(
                    EnrollmentKeyBuilder::single(Scenario::name('key'))->tags($tag)->expiresAt(new DateTimeImmutable('+2 hours')),
                );
                $key = $created->key;
                // Registered before anything else can fail, so a working key never stays behind.
                $cleanup->add('enrollment key ' . $key->name, static fn() => $client->enrollmentKeys()->revoke($key->id));
                DtoAudit::inspect($created, 'enrollmentKeys.create');
                $plain = $created->token->reveal();

                expect($key->type)->toBe(EnrollmentKeyType::Single)
                    ->and($key->status)->toBe(EnrollmentKeyStatus::Active)
                    ->and($key->name)->toBe(Scenario::name('key'))
                    ->and($key->uses)->toBe(0)
                    ->and($key->maxUses)->toBe(1)
                    ->and($key->tagIds())->toBe([$tag->id])
                    ->and($key->hasTag($tag->label))->toBeTrue()
                    ->and($key->agentTags)->toBeTrue()
                    ->and($key->services)->toBe([])
                    ->and($key->expiresAt > new DateTimeImmutable('+1 hour'))->toBeTrue()
                    ->and($key->expiresAt < new DateTimeImmutable('+3 hours'))->toBeTrue()
                    ->and($plain)->toStartWith('lve_')
                    ->and(str_starts_with($plain, $key->tokenPrefix))->toBeTrue()
                    ->and(str_ends_with($created->installCommand->reveal(), ' ' . $plain))->toBeTrue()
                    ->and((string) json_encode($created))->not->toContain($plain)
                    ->and(print_r($created, true))->not->toContain($plain)
                    ->and($key->raw)->not->toHaveKeys(['token', 'install_command']);

                return $created;
            });
            $key = $created->key;

            $journey->step('3 read back: the key never again, the list finds it among the active ones', function () use ($client, $key, $created): void {
                $read = DtoAudit::inspect($client->enrollmentKeys()->get($key->id), 'enrollmentKeys.get');
                $active = [];

                foreach ($client->enrollmentKeys()->each(EnrollmentKeyQuery::make()->withStatuses(EnrollmentKeyStatus::Active)->withPerPage(100)) as $listed) {
                    $active[] = DtoAudit::inspect($listed, 'enrollmentKeys.each')->id;
                }

                expect($read->tokenPrefix)->toBe($key->tokenPrefix)
                    ->and($read->raw)->not->toHaveKeys(['token', 'install_command'])
                    ->and((string) json_encode($read->raw))->not->toContain($created->token->reveal())
                    ->and($read->status)->toBe(EnrollmentKeyStatus::Active)
                    ->and($active)->toContain($key->id);
            });

            $existingTagIds = $journey->step('4 the tags the organization has before the install', static function () use ($client): array {
                $ids = [];

                foreach ($client->tags()->each(TagQuery::make()->withPerPage(100)) as $existing) {
                    $ids[$existing->id] = true;
                }

                return $ids;
            });

            $serviceId = $journey->step('5 the installer enrolls with the key, asking for a second customer tag and one more', function () use ($created, $client, $cleanup, $hostname, $override, $extra): string {
                $installer = LiveApi::clientWithToken($created->token->reveal());
                $response = $installer->request('POST', 'agents/enroll', json: [
                    'enrollment_id' => Uuid::v4(),
                    'instance_id' => Uuid::v4(),
                    'hostname' => $hostname,
                    'tags' => [$override, $extra],
                    'meta' => [
                        'os' => 'linux',
                        'distro' => 'ubuntu',
                        'distro_version' => '24.04',
                        'arch' => 'amd64',
                        'virtualization' => 'kvm',
                        'ips_private' => ['10.0.0.5'],
                        'ips_public' => ['203.0.113.7'],
                    ],
                    'agent_version' => '1.0.0',
                ]);
                $body = $response->json();
                $service = is_array($body['service'] ?? null) ? $body['service'] : [];
                $serviceId = is_string($service['public_id'] ?? null) ? $service['public_id'] : '';

                if ($serviceId !== '') {
                    $cleanup->add('server ' . $hostname, static fn() => $client->services()->delete($serviceId, deleteOrphanedIncidents: true, deleteOrphanedMaintenances: true));
                }

                expect($response->status())->toBe(201)
                    ->and($serviceId)->not->toBe('')
                    ->and($body['ignored_tags'] ?? null)->toBe([$override]);

                return $serviceId;
            });

            $journey->step('6 the key is exhausted and names the server', function () use ($client, $key, $serviceId, $hostname): void {
                $read = DtoAudit::inspect($client->enrollmentKeys()->get($key->id), 'enrollmentKeys.get');

                expect($read->status)->toBe(EnrollmentKeyStatus::Exhausted)
                    ->and($read->isActive())->toBeFalse()
                    ->and($read->uses)->toBe(1)
                    ->and($read->services)->toHaveCount(1)
                    ->and($read->latestService()?->id)->toBe($serviceId)
                    ->and($read->latestService()?->name)->toBe($hostname);
            });

            $journey->step("7 the server carries the key's tag and the extra one, never the second customer tag", function () use ($client, $serviceId, $tag, $override, $extra, $existingTagIds, &$madeByTheEnrollment): void {
                $service = DtoAudit::inspect($client->services()->get($serviceId), 'services.get');
                $userTags = [];

                foreach ($service->tags ?? [] as $carried) {
                    if ($carried->source === TagSource::User) {
                        $userTags[] = $carried->label;

                        if (! isset($existingTagIds[$carried->id])) {
                            $madeByTheEnrollment[] = $carried;
                        }
                    }
                }

                sort($userTags);
                $expected = [$tag->label, $extra];
                sort($expected);

                expect($service->checkType)->toBe(CheckType::Agent)
                    ->and($userTags)->toBe($expected)
                    ->and($service->hasTag($override))->toBeFalse();
            });

            $journey->step('8 its agent block: host name, host facts, the key it enrolled with', function () use ($client, $serviceId, $hostname, $key): void {
                $service = DtoAudit::inspect($client->services()->get($serviceId), 'services.get');
                $agent = $service->agent;

                expect($agent)->not->toBeNull()
                    ->and($agent?->hostname)->toBe($hostname)
                    ->and($agent?->enrollmentKeyId)->toBe($key->id)
                    ->and($agent?->state)->toBe(AgentState::Waiting)
                    ->and($agent?->lastSeenAt)->toBeNull()
                    ->and($agent?->version)->toBe('1.0.0')
                    ->and($agent?->os)->toBe('linux')
                    ->and($agent?->distro)->toBe('ubuntu')
                    ->and($agent?->distroVersion)->toBe('24.04')
                    ->and($agent?->arch)->toBe('amd64')
                    ->and($agent?->virtualization)->toBe('kvm')
                    ->and($agent?->ips->private)->toBe(['10.0.0.5'])
                    ->and($agent?->ips->public)->toBe(['203.0.113.7']);
            });

            $journey->step('9 its figures answer in shape before a report; a service that is no agent has none', function () use ($client, $cleanup, $serviceId): void {
                $figures = DtoAudit::inspect($client->services()->agentMetrics($serviceId), 'services.agentMetrics');
                $window = DtoAudit::inspect($client->services()->agentMetricsHistory($serviceId, AgentMetricsRange::OneHour), 'services.agentMetricsHistory');
                $picked = DtoAudit::inspect(
                    $client->services()->agentMetricsHistory($serviceId, AgentMetricsRange::SixHours, 'sys.cpu.total_pct', 'sys.disk._root.used_pct'),
                    'services.agentMetricsHistory',
                );

                expect($figures)->toBeInstanceOf(AgentMetrics::class)
                    ->and(array_keys($figures->metrics))->toContain('sys.cpu.total_pct', 'sys.mem.used_pct', 'sys.load.1')
                    ->and(array_is_list($figures->disks) && array_is_list($figures->gpus) && array_is_list($figures->smart) && array_is_list($figures->probes))->toBeTrue()
                    ->and($window)->toBeInstanceOf(AgentMetricsHistory::class)
                    ->and($window->windowSeconds)->toBe(3600)
                    ->and(array_is_list($window->timestamps))->toBeTrue()
                    ->and($picked->windowSeconds)->toBe(21600)
                    ->and(array_keys($picked->metrics))->toBe(['sys.cpu.total_pct', 'sys.disk._root.used_pct'])
                    ->and(array_keys($picked->stats))->toBe(['sys.cpu.total_pct', 'sys.disk._root.used_pct']);

                try {
                    $client->services()->agentMetricsHistory($serviceId, AgentMetricsRange::OneHour, 'sys.cpu.total_pct', 'sys.agent.version');
                    expect(false)->toBeTrue('A history of the agent\'s own telemetry was accepted.');
                } catch (ValidationException $e) {
                    expect($e->status())->toBe(422)
                        ->and($e->hasError('keys.1'))->toBeTrue()
                        ->and($e->hasError('keys.0'))->toBeFalse();
                }

                $manual = Scenario::manualService($client, $cleanup, 'no-agent');

                expect(static fn(): AgentMetrics => $client->services()->agentMetrics($manual->id))->toThrow(NotFoundException::class)
                    ->and(static fn(): AgentMetricsHistory => $client->services()->agentMetricsHistory($manual->id))->toThrow(NotFoundException::class)
                    ->and(DtoAudit::inspect($client->services()->get($manual->id), 'services.get')->agent)->toBeNull();
            });

            $journey->step('10 revoke: a fresh fleet key twice, the used key once; revoked wins', function () use ($client, $cleanup, $key): void {
                $createdFleet = $client->enrollmentKeys()->create(EnrollmentKeyBuilder::fleet(Scenario::name('fleet'), 2)->expiresAt(new DateTimeImmutable('+2 hours')));
                $fleet = $createdFleet->key;
                $cleanup->add('enrollment key ' . $fleet->name, static fn() => $client->enrollmentKeys()->revoke($fleet->id));
                DtoAudit::inspect($createdFleet, 'enrollmentKeys.create');

                $client->enrollmentKeys()->revoke($fleet->id);
                $client->enrollmentKeys()->revoke($fleet->id);
                $client->enrollmentKeys()->revoke($key->id);

                $revokedFleet = DtoAudit::inspect($client->enrollmentKeys()->get($fleet->id), 'enrollmentKeys.get');
                $revokedKey = DtoAudit::inspect($client->enrollmentKeys()->get($key->id), 'enrollmentKeys.get');
                $revoked = [];

                foreach ($client->enrollmentKeys()->each(EnrollmentKeyQuery::make()->withStatuses(EnrollmentKeyStatus::Revoked)->withPerPage(100)) as $listed) {
                    $revoked[] = $listed->id;
                }

                expect($fleet->type)->toBe(EnrollmentKeyType::Fleet)
                    ->and($fleet->maxUses)->toBe(2)
                    ->and($revokedFleet->status)->toBe(EnrollmentKeyStatus::Revoked)
                    ->and($revokedFleet->revokedAt)->not->toBeNull()
                    ->and($revokedKey->status)->toBe(EnrollmentKeyStatus::Revoked)
                    ->and($revokedKey->uses)->toBe(1)
                    ->and($revoked)->toContain($fleet->id, $key->id)
                    ->and(static fn(): EnrollmentKey => $client->enrollmentKeys()->get('Zz' . substr(bin2hex(random_bytes(10)), 0, 19)))->toThrow(NotFoundException::class);
            });

            $journey->step('11 a blank tag is refused before anything is sent', function (): void {
                $sent = LiveApi::attempts()->attempts();

                expect(static fn(): EnrollmentKeyBuilder => EnrollmentKeyBuilder::single()->tags('customer:4711', ' '))->toThrow(InvalidArgumentException::class);
                expect(LiveApi::attempts()->attempts())->toBe($sent, 'A client-side refusal sent a request.');
            });
        } finally {
            $cleanupFailures = $journey->step('12 cleanup: the server and the service, the keys, the tags', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
