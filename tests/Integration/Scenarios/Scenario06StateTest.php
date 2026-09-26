<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Enums\PausedReason;
use LIVCK\Cloud\Enums\ServiceStatus;
use LIVCK\Cloud\Enums\StatusOverride;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 6, state: pause and resume a monitored service, and switch a manual service
 * through the status override (apply with a reason, effective status follows, remove).
 */
$skip = LiveApi::skipReason();

describe('scenario 6: state', function () use ($skip): void {
    it('pauses, resumes and overrides', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $cleanupFailures = [];

        try {
            $http = Scenario::service($client, $cleanup, ServiceBuilder::http(Scenario::name('state-http'), 'https://example.com/')->interval(30)->probes('ffm', 'hel', 'nbg'));
            $manual = Scenario::manualService($client, $cleanup, 'state-manual');

            $journey->step('1 pause: is_paused and the manual reason; a second pause changes nothing', function () use ($client, $http, $journey): void {
                $paused = DtoAudit::inspect($client->services()->pause($http->id), 'services.pause');
                $again = $client->services()->pause($http->id);
                $fresh = $client->services()->get($http->id);

                // The pause is carried by `is_paused` and `paused_reason`; `status` keeps the last
                // measured value (unknown for a service the probes have not reached yet).
                expect($paused->isPaused)->toBeTrue()
                    ->and($paused->pausedReason)->toBe(PausedReason::Manual)
                    ->and($again->isPaused)->toBeTrue()
                    ->and($again->pausedReason)->toBe(PausedReason::Manual)
                    ->and($fresh->isPaused)->toBeTrue()
                    ->and($fresh->pausedReason)->toBe(PausedReason::Manual);

                $journey->note(sprintf('paused: status %s, effective %s', $fresh->status->value, $fresh->effectiveStatus->value));
            });

            $journey->step('2 resume: monitoring again; a second resume changes nothing', function () use ($client, $http): void {
                $resumed = DtoAudit::inspect($client->services()->resume($http->id), 'services.resume');
                $again = $client->services()->resume($http->id);
                $fresh = $client->services()->get($http->id);

                expect($resumed->isPaused)->toBeFalse()
                    ->and($resumed->pausedReason)->toBeNull()
                    ->and($again->isPaused)->toBeFalse()
                    ->and($again->pausedReason)->toBeNull()
                    ->and($fresh->isPaused)->toBeFalse()
                    ->and($fresh->pausedReason)->toBeNull();
            });

            $journey->step('3 a manual service reads healthy until an override pins it', function () use ($client, $manual, $journey): void {
                $journey->note(sprintf('new manual service: status %s, effective %s', $manual->status->value, $manual->effectiveStatus->value));

                expect($manual->effectiveStatus->isHealthy())->toBeTrue($manual->effectiveStatus->value)
                    ->and($manual->hasStatusOverride())->toBeFalse()
                    ->and($manual->statusOverride)->toBeNull()
                    ->and($manual->statusOverrideReason)->toBeNull()
                    ->and($manual->statusOverrideAt)->toBeNull();

                $down = DtoAudit::inspect($client->services()->applyStatusOverride($manual->id, StatusOverride::Down, 'Phone lines cut by roadworks'), 'services.applyStatusOverride');
                $fresh = $client->services()->get($manual->id);

                foreach ([$down, $fresh] as $service) {
                    expect($service->hasStatusOverride())->toBeTrue()
                        ->and($service->statusOverride)->toBe(StatusOverride::Down)
                        ->and($service->effectiveStatus)->toBe(ServiceStatus::Down)
                        ->and($service->statusOverrideReason)->toBe('Phone lines cut by roadworks')
                        ->and($service->statusOverrideAt)->not->toBeNull();
                }

                $maintenance = $client->services()->applyStatusOverride($manual->id, StatusOverride::Maintenance, 'PBX upgrade');

                expect($maintenance->statusOverride)->toBe(StatusOverride::Maintenance)
                    ->and($maintenance->effectiveStatus)->toBe(ServiceStatus::Maintenance)
                    ->and($maintenance->statusOverrideReason)->toBe('PBX upgrade');
            });

            $journey->step('4 remove the override: healthy again; removing twice is a no-op', function () use ($client, $manual): void {
                $removed = DtoAudit::inspect($client->services()->removeStatusOverride($manual->id), 'services.removeStatusOverride');
                $again = $client->services()->removeStatusOverride($manual->id);

                expect($removed->hasStatusOverride())->toBeFalse()
                    ->and($removed->statusOverride)->toBeNull()
                    ->and($removed->statusOverrideReason)->toBeNull()
                    ->and($removed->effectiveStatus->isHealthy())->toBeTrue($removed->effectiveStatus->value)
                    ->and($again->hasStatusOverride())->toBeFalse()
                    ->and($client->services()->get($manual->id)->effectiveStatus->isHealthy())->toBeTrue();
            });

            $journey->step('5 an override on a measured service masks the live result', function () use ($client, $http): void {
                $degraded = $client->services()->applyStatusOverride($http->id, StatusOverride::Degraded, 'Known slow path');
                $removed = $client->services()->removeStatusOverride($http->id);

                expect($degraded->effectiveStatus)->toBe(ServiceStatus::Degraded)
                    ->and($degraded->statusOverride)->toBe(StatusOverride::Degraded)
                    ->and($degraded->status)->not->toBe(ServiceStatus::Degraded)
                    ->and($removed->statusOverride)->toBeNull()
                    ->and($removed->effectiveStatus)->toBe($removed->status);
            });

            $journey->step('6 a blank or overlong reason and an unknown status are refused before sending', function () use ($client, $manual): void {
                $sent = LiveApi::attempts()->attempts();

                expect(static fn(): Service => $client->services()->applyStatusOverride($manual->id, StatusOverride::Down, '  '))->toThrow(InvalidArgumentException::class);
                expect(static fn(): Service => $client->services()->applyStatusOverride($manual->id, StatusOverride::Down, str_repeat('x', 501)))->toThrow(InvalidArgumentException::class);
                expect(static fn(): Service => $client->services()->applyStatusOverride($manual->id, StatusOverride::Unrecognized, 'x'))->toThrow(InvalidArgumentException::class);
                expect(LiveApi::attempts()->attempts())->toBe($sent);
            });
        } finally {
            $cleanupFailures = $journey->step('7 cleanup: the services', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
