<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\ServiceSettings;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Enums\ConditionOutcome;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\KeepSecret;
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
