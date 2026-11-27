<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\AgentIps;
use LIVCK\Cloud\Data\AgentUpdateSettings;
use LIVCK\Cloud\Data\DeclaredSchedule;
use LIVCK\Cloud\Data\HeartbeatOrigin;
use LIVCK\Cloud\Data\ObservedIp;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\ServiceAgent;
use LIVCK\Cloud\Data\ServiceHeartbeat;
use LIVCK\Cloud\Data\ServiceSettings;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\AgentState;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Enums\ConditionOutcome;
use LIVCK\Cloud\Enums\HeartbeatKind;
use LIVCK\Cloud\Enums\HeartbeatScheduleType;
use LIVCK\Cloud\Enums\IpFamily;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Enums\TagSource;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\KeepSecret;
use LIVCK\Cloud\Tests\Fixtures\EnrollmentKeyFixtures;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;

it('reads a service that was listed without its tags', function (): void {
    $payload = ServiceFixtures::payload();
    unset($payload['tags']);

    $service = Service::fromArray($payload);

    expect($service->tags)->toBeNull()
        ->and($service->tagIds())->toBe([])
        ->and($service->hasTag('kunde:4711'))->toBeFalse();
});

it('tells passive types from those probes check', function (): void {
    expect(Service::fromArray(ServiceFixtures::payload(['check_type' => 'manual', 'target' => null]))->isMonitoredByProbes())->toBeFalse()
        ->and(Service::fromArray(ServiceFixtures::payload(['check_type' => 'statuspage']))->checkType)->toBe(CheckType::Statuspage)
        ->and(Service::fromArray(ServiceFixtures::payload(['check_type' => 'statuspage']))->isMonitoredByProbes())->toBeTrue()
        ->and(Service::fromArray(ServiceFixtures::payload(['check_type' => 'agent']))->isMonitoredByProbes())->toBeFalse();
});

it('accepts integral uptime and response figures', function (): void {
    $service = Service::fromArray(ServiceFixtures::payload(['uptime_30d' => 100, 'avg_response_ms' => 36]));

    expect($service->uptime30d)->toBe(100.0)
        ->and($service->avgResponseMs)->toBe(36.0);
});

it('fails loudly on a payload that drifted from the documented shape', function (): void {
    expect(fn(): Service => Service::fromArray(ServiceFixtures::payload(['is_paused' => 'no'])))
        ->toThrow(UnexpectedResponseException::class, 'is_paused');
});

describe('settings', function (): void {
    it('reads the schedule, locations, roles and masked config', function (): void {
        $settings = ServiceSettings::fromArray(ServiceFixtures::settings());

        expect($settings->intervalSeconds)->toBe(60)
            ->and($settings->assignedProbes)->toBe(['ffm', 'hel'])
            ->and($settings->inheritsProbes())->toBeFalse()
            ->and($settings->probeRoles)->toBe(['hel' => ProbeRole::Reachability])
            ->and($settings->config['auth'])->toBe(['type' => 'bearer', 'token' => KeepSecret::SENTINEL])
            ->and(KeepSecret::isSentinel($settings->headers()['X-Api-Key']))->toBeTrue()
            ->and($settings->value('ip_version'))->toBe('auto')
            ->and($settings->value('missing', 'fallback'))->toBe('fallback')
            ->and($settings->raw)->toBe(ServiceFixtures::settings());

        $conditions = $settings->conditions();

        expect($conditions)->toHaveCount(1)
            ->and($conditions[0]->operator)->toBe(ConditionOperator::Gte)
            ->and($conditions[0]->outcome)->toBe(ConditionOutcome::Down);
    });

    it('reads inherited locations and roles as null', function (): void {
        $settings = ServiceSettings::fromArray(ServiceFixtures::settings(['assigned_probes' => null, 'probe_roles' => null]));

        expect($settings->assignedProbes)->toBeNull()
            ->and($settings->inheritsProbes())->toBeTrue()
            ->and($settings->probeRoles)->toBeNull();
    });

    it('reads a config without headers or conditions', function (): void {
        $settings = ServiceSettings::fromArray(ServiceFixtures::settings(['config' => ['dns_type' => 'NS']]));

        expect($settings->headers())->toBe([])
            ->and($settings->conditions())->toBe([]);
    });

    it('refuses a role map with a non-string role', function (): void {
        expect(fn(): ServiceSettings => ServiceSettings::fromArray(ServiceFixtures::settings(['probe_roles' => ['ffm' => 1]])))
            ->toThrow(UnexpectedResponseException::class, 'probe_roles.ffm');
    });
});

describe('agent', function (): void {
    it('reads the server behind an agent service', function (): void {
        $service = Service::fromArray(ServiceFixtures::agentPayload());
        $agent = $service->agent;

        expect($service->checkType)->toBe(CheckType::Agent)
            ->and($service->target)->toBe('web-1')
            ->and($service->settings?->config)->toBe([])
            ->and($agent)->toBeInstanceOf(ServiceAgent::class)
            ->and($agent?->state)->toBe(AgentState::Online)
            ->and($agent?->state->isOutage())->toBeFalse()
            ->and($agent?->stateChangedAt?->format(DATE_ATOM))->toBe('2026-10-01T08:00:00+00:00')
            ->and($agent?->lastSeenAt?->format(DATE_ATOM))->toBe('2026-10-01T11:59:30+00:00')
            ->and($agent?->hostname)->toBe('web-1')
            ->and($agent?->version)->toBe('1.4.0')
            ->and($agent?->os)->toBe('linux')
            ->and($agent?->distro)->toBe('debian')
            ->and($agent?->distroVersion)->toBe('12')
            ->and($agent?->kernel)->toBe('6.1.0-26-amd64')
            ->and($agent?->arch)->toBe('amd64')
            ->and($agent?->virtualization)->toBe('kvm')
            ->and($agent?->cpuModel)->toBe('AMD EPYC-Rome Processor')
            ->and($agent?->cpuCores)->toBe(8)
            ->and($agent?->ramTotalBytes)->toBe(17179869184)
            ->and($agent?->bootedAt?->format(DATE_ATOM))->toBe('2026-09-21T14:13:20+00:00')
            ->and($agent?->rebootRequired)->toBeFalse()
            ->and($agent?->instanceConflictAt)->toBeNull()
            ->and($agent?->enrollmentKeyId)->toBe(EnrollmentKeyFixtures::ID)
            ->and($agent?->raw)->toBe(ServiceFixtures::agent())
            ->and($service->raw['agent'])->toBe(ServiceFixtures::agent());

        $ips = $agent?->ips;

        expect($ips)->toBeInstanceOf(AgentIps::class)
            ->and($ips?->private)->toBe(['10.0.0.5'])
            ->and($ips?->public)->toBe(['203.0.113.7', '2001:db8::7'])
            ->and($ips?->observed)->toHaveCount(2)
            ->and($ips?->observed[0])->toBeInstanceOf(ObservedIp::class)
            ->and($ips?->observed[0]->ip)->toBe('203.0.113.7')
            ->and($ips?->observed[0]->family)->toBe(IpFamily::V4)
            ->and($ips?->observed[0]->at?->format(DATE_ATOM))->toBe('2026-10-01T11:58:00+00:00')
            ->and($ips?->observed[1]->family)->toBe(IpFamily::V6)
            ->and($ips?->observed[1]->at)->toBeNull();

        $update = $agent?->update;

        expect($update)->toBeInstanceOf(AgentUpdateSettings::class)
            ->and($update?->automatic)->toBeTrue()
            ->and($update?->windowStart)->toBe('00:00')
            ->and($update?->windowEnd)->toBe('04:00')
            ->and($update?->availableVersion)->toBe('1.5.0');
    });

    it('reads a server that has not reported yet', function (): void {
        $agent = Service::fromArray(ServiceFixtures::agentPayload([
            'state' => 'waiting',
            'state_changed_at' => null,
            'last_seen_at' => null,
            'version' => null,
            'os' => null,
            'cpu_cores' => null,
            'ram_total_bytes' => null,
            'booted_at' => null,
            'ips' => ['private' => [], 'public' => [], 'observed' => []],
            'update' => ['automatic' => false, 'window_start' => '22:30', 'window_end' => '23:30', 'available_version' => null],
            'instance_conflict_at' => '2026-10-01T10:00:00+00:00',
            'enrollment_key_id' => null,
        ]))->agent;

        expect($agent?->state)->toBe(AgentState::Waiting)
            ->and($agent?->lastSeenAt)->toBeNull()
            ->and($agent?->version)->toBeNull()
            ->and($agent?->cpuCores)->toBeNull()
            ->and($agent?->ips->observed)->toBe([])
            ->and($agent?->update->automatic)->toBeFalse()
            ->and($agent?->update->windowStart)->toBe('22:30')
            ->and($agent?->update->availableVersion)->toBeNull()
            ->and($agent?->instanceConflictAt?->format(DATE_ATOM))->toBe('2026-10-01T10:00:00+00:00')
            ->and($agent?->enrollmentKeyId)->toBeNull();
    });

    it('keeps unknown states and families readable', function (): void {
        $agent = Service::fromArray(ServiceFixtures::agentPayload([
            'state' => 'hibernating',
            'ips' => ['private' => [], 'public' => [], 'observed' => [['ip' => '203.0.113.7', 'family' => 'v8', 'at' => null]]],
        ]))->agent;

        expect($agent?->state)->toBe(AgentState::Unrecognized)
            ->and($agent?->state->isOutage())->toBeFalse()
            ->and($agent?->raw['state'])->toBe('hibernating')
            ->and($agent?->ips->observed[0]->family)->toBe(IpFamily::Unrecognized)
            ->and($agent?->ips->observed[0]->raw['family'])->toBe('v8');
    });

    it('is null for every other service, and for a payload from before the field', function (): void {
        $payload = ServiceFixtures::payload();
        unset($payload['agent']);

        expect(Service::fromArray(ServiceFixtures::payload())->agent)->toBeNull()
            ->and(Service::fromArray($payload)->agent)->toBeNull();
    });

    it('keeps a constructor call written for earlier versions working', function (): void {
        $service = Service::fromArray(ServiceFixtures::payload());

        $copy = new Service(
            $service->id,
            $service->name,
            $service->checkType,
            $service->checkTypeLabel,
            $service->target,
            $service->status,
            $service->effectiveStatus,
            $service->statusOverride,
            $service->statusOverrideReason,
            $service->statusOverrideAt,
            $service->faviconUrl,
            $service->isPaused,
            $service->pausedReason,
            $service->configuredAt,
            $service->isConfigured,
            $service->uptime30d,
            $service->avgResponseMs,
            $service->tags,
            $service->lastCheckAt,
            $service->createdAt,
            $service->settings,
            $service->raw,
        );

        expect($copy->agent)->toBeNull();
    });

    it('fails loudly on an agent block that drifted from the documented shape', function (): void {
        $drifts = [
            'hostname' => ['hostname' => null],
            'private[0]' => ['ips' => ['private' => [10], 'public' => [], 'observed' => []]],
            'booted_at' => ['booted_at' => 'yesterday'],
            'update' => ['update' => ['00:00', '04:00']],
        ];

        foreach ($drifts as $field => $overrides) {
            expect(fn(): Service => Service::fromArray(ServiceFixtures::agentPayload($overrides)))
                ->toThrow(UnexpectedResponseException::class, $field);
        }
    });
});

describe('heartbeat', function (): void {
    it('reads the schedule, bounds, last ping and the application that declares it', function (): void {
        $service = Service::fromArray(ServiceFixtures::heartbeatPayload());
        $heartbeat = $service->heartbeat;

        expect($service->checkType)->toBe(CheckType::Heartbeat)
            ->and($service->target)->toBeNull()
            ->and($service->settings)->toBeNull()
            ->and($service->agent)->toBeNull()
            ->and($service->isMonitoredByProbes())->toBeFalse()
            ->and($heartbeat)->toBeInstanceOf(ServiceHeartbeat::class)
            ->and($heartbeat?->schedule)->toBeInstanceOf(DeclaredSchedule::class)
            ->and($heartbeat?->schedule->type)->toBe(HeartbeatScheduleType::Simple)
            ->and($heartbeat?->schedule->periodSeconds)->toBe(300)
            ->and($heartbeat?->schedule->expression)->toBeNull()
            ->and($heartbeat?->schedule->timezone)->toBe('UTC')
            ->and($heartbeat?->graceSeconds)->toBe(60)
            ->and($heartbeat?->scheduleTolerance)->toBe(2)
            ->and($heartbeat?->maxDurationSeconds)->toBe(900)
            ->and($heartbeat?->lastPingAt?->format(DATE_ATOM))->toBe('2026-11-27T08:15:00+00:00')
            ->and($heartbeat?->isDeclared())->toBeTrue()
            ->and($heartbeat?->declared)->toBeInstanceOf(HeartbeatOrigin::class)
            ->and($heartbeat?->declared?->app)->toBe('shop')
            ->and($heartbeat?->declared?->environment)->toBe('production')
            ->and($heartbeat?->declared?->key)->toBe('export:nightly')
            ->and($heartbeat?->declared?->kind)->toBe(HeartbeatKind::Schedule)
            ->and($heartbeat?->declared?->raw)->toBe(ServiceFixtures::heartbeat()['declared'])
            ->and($heartbeat?->raw)->toBe(ServiceFixtures::heartbeat())
            ->and($service->raw['heartbeat'])->toBe(ServiceFixtures::heartbeat());
    });

    it('carries the system tags of the declaration', function (): void {
        $service = Service::fromArray(ServiceFixtures::heartbeatPayload());

        expect($service->hasTag('livck.app:shop'))->toBeTrue()
            ->and($service->hasTag('livck.env:production'))->toBeTrue()
            ->and(array_map(static fn(Tag $tag): TagSource => $tag->source, $service->tags ?? []))->toBe([TagSource::System, TagSource::System])
            ->and(array_map(static fn(Tag $tag): bool => $tag->isSystem(), $service->tags ?? []))->toBe([true, true]);
    });

    it('reads a heartbeat created by hand on a cron schedule that has not pinged yet', function (): void {
        $heartbeat = Service::fromArray(ServiceFixtures::heartbeatPayload(ServiceFixtures::heartbeatByHand()))->heartbeat;

        expect($heartbeat?->schedule->isCron())->toBeTrue()
            ->and($heartbeat?->schedule->periodSeconds)->toBeNull()
            ->and($heartbeat?->schedule->expression)->toBe('0 3 * * *')
            ->and($heartbeat?->schedule->timezone)->toBe('Europe/Berlin')
            ->and($heartbeat?->scheduleTolerance)->toBe(1)
            ->and($heartbeat?->maxDurationSeconds)->toBeNull()
            ->and($heartbeat?->lastPingAt)->toBeNull()
            ->and($heartbeat?->declared)->toBeNull()
            ->and($heartbeat?->isDeclared())->toBeFalse();
    });

    it('is null for every other check type, and when the server leaves the key out', function (): void {
        $listed = ServiceFixtures::heartbeatPayload();
        unset($listed['heartbeat']);

        expect(Service::fromArray(ServiceFixtures::payload())->heartbeat)->toBeNull()
            ->and(Service::fromArray(ServiceFixtures::agentPayload())->heartbeat)->toBeNull()
            ->and(Service::fromArray($listed)->heartbeat)->toBeNull();
    });

    it('keeps an unknown kind or schedule type readable', function (): void {
        $heartbeat = Service::fromArray(ServiceFixtures::heartbeatPayload([
            'schedule' => ['type' => 'rrule', 'period_seconds' => null, 'expression' => 'FREQ=DAILY', 'timezone' => 'UTC'],
            'declared' => ['app' => 'shop', 'environment' => 'production', 'key' => 'export:nightly', 'kind' => 'stream'],
        ]))->heartbeat;

        expect($heartbeat?->schedule->type)->toBe(HeartbeatScheduleType::Unrecognized)
            ->and($heartbeat?->schedule->raw['type'])->toBe('rrule')
            ->and($heartbeat?->declared?->kind)->toBe(HeartbeatKind::Unrecognized)
            ->and($heartbeat?->declared?->raw['kind'])->toBe('stream');
    });

    it('fails loudly on a heartbeat block that drifted from the documented shape', function (string $key, mixed $value, string $field): void {
        expect(fn(): Service => Service::fromArray(ServiceFixtures::heartbeatPayload([$key => $value])))
            ->toThrow(UnexpectedResponseException::class, $field);
    })->with([
        'grace as a string' => ['grace_seconds', '60', 'grace_seconds'],
        'no tolerance' => ['schedule_tolerance', null, 'schedule_tolerance'],
        'no schedule' => ['schedule', null, 'schedule'],
        'schedule without a timezone' => ['schedule', ['type' => 'simple', 'period_seconds' => 300, 'expression' => null], 'timezone'],
        'a last ping that is no instant' => ['last_ping_at', 'yesterday', 'last_ping_at'],
        'declared without its key' => ['declared', ['app' => 'shop', 'environment' => 'production', 'kind' => 'schedule'], 'key'],
        'declared as a list' => ['declared', ['shop', 'production'], 'declared'],
    ]);
});
