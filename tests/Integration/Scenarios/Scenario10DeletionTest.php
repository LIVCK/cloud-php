<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\Incident;
use LIVCK\Cloud\Data\Maintenance;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Enums\IncidentStatus;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Support\Dates;
use LIVCK\Cloud\Support\Path;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 10, deletion: what a service takes along. With the two flags, the incidents and
 * maintenance windows it carried alone are erased; without them they are closed. Shared
 * ones are only detached. A DELETE of a service that is already gone is not found.
 */
$skip = LiveApi::skipReason();

describe('scenario 10: deletion', function () use ($skip): void {
    it('erases or closes what a service carried alone and detaches what it shared', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $cleanupFailures = [];

        try {
            $one = Scenario::manualService($client, $cleanup, 'del-1');
            $two = Scenario::manualService($client, $cleanup, 'del-2');

            $linked = $journey->step('1 two incidents and two windows: one each on del-1 alone, one each shared with del-2', function () use ($client, $cleanup, $one, $two): array {
                $incident = static function (string $suffix, Service ...$services) use ($client, $cleanup): Incident {
                    $incident = DtoAudit::inspect(Incident::fromArray(Scenario::post($client, 'incidents', [
                        'title' => Scenario::name($suffix),
                        'message' => 'Created by the SDK scenario suite.',
                        'severity' => 'minor',
                        'is_published' => false,
                        'notify_subscribers' => false,
                        'service_ids' => Scenario::ids(array_values($services)),
                    ])), 'incident (escape hatch)');
                    $cleanup->add('incident ' . $incident->title, static function () use ($client, $incident): void {
                        $client->request('DELETE', Path::join('incidents', $incident->id));
                    });

                    return $incident;
                };
                $window = static function (string $suffix, Service ...$services) use ($client, $cleanup): Maintenance {
                    $window = DtoAudit::inspect(Maintenance::fromArray(Scenario::post($client, 'maintenances', [
                        'title' => Scenario::name($suffix),
                        'scheduled_start' => Dates::format(new DateTimeImmutable('+1 hour')),
                        'scheduled_end' => Dates::format(new DateTimeImmutable('+2 hours')),
                        'service_ids' => Scenario::ids(array_values($services)),
                        'notify_announcement' => false,
                        'notify_24h' => false,
                        'notify_1h' => false,
                        'notify_start' => false,
                        'notify_complete' => false,
                    ])), 'maintenance (escape hatch)');
                    $cleanup->add('maintenance ' . $window->title, static function () use ($client, $window): void {
                        $client->request('DELETE', Path::join('maintenances', $window->id));
                    });

                    return $window;
                };

                return [
                    'alone' => $incident('del-incident-alone', $one),
                    'shared' => $incident('del-incident-shared', $one, $two),
                    'window' => $window('del-window-alone', $one),
                    'sharedWindow' => $window('del-window-shared', $one, $two),
                ];
            });

            $journey->step('2 delete del-1 with both flags: its own incident and window are erased, the shared ones detached', function () use ($client, $one, $two, $linked): void {
                $client->services()->delete($one->id, deleteOrphanedIncidents: true, deleteOrphanedMaintenances: true);

                expect(static fn(): Service => $client->services()->get($one->id))->toThrow(NotFoundException::class);
                expect(static fn(): Incident => $client->incidents()->get($linked['alone']->id))->toThrow(NotFoundException::class);
                expect(static fn(): Maintenance => $client->maintenances()->get($linked['window']->id))->toThrow(NotFoundException::class);

                $shared = DtoAudit::inspect($client->incidents()->get($linked['shared']->id), 'incidents.get');
                $sharedWindow = DtoAudit::inspect($client->maintenances()->get($linked['sharedWindow']->id), 'maintenances.get');

                expect($shared->isOpen())->toBeTrue()
                    ->and($shared->serviceIds())->toBe([$two->id])
                    ->and($sharedWindow->status)->toBe(MaintenanceStatus::Scheduled)
                    ->and($sharedWindow->serviceIds())->toBe([$two->id]);
            });

            $journey->step('3 delete del-2 without flags: the now orphaned incident is resolved, the window cancelled', function () use ($client, $two, $linked): void {
                $client->services()->delete($two->id);

                $shared = $client->incidents()->get($linked['shared']->id);
                $sharedWindow = $client->maintenances()->get($linked['sharedWindow']->id);

                expect($shared->isResolved())->toBeTrue()
                    ->and($shared->status)->toBe(IncidentStatus::Resolved)
                    ->and($shared->resolvedAt)->not->toBeNull()
                    ->and($shared->serviceIds())->toBe([])
                    ->and($sharedWindow->status)->toBe(MaintenanceStatus::Cancelled)
                    ->and($sharedWindow->isActive())->toBeFalse()
                    ->and($sharedWindow->serviceIds())->toBe([]);
            });

            $journey->step('4 a DELETE of a service that is already gone is not found', function () use ($client, $two): void {
                try {
                    $client->services()->delete($two->id);
                    expect(false)->toBeTrue('Deleting a deleted service succeeded.');
                } catch (NotFoundException $e) {
                    expect($e->status())->toBe(404);
                }

                expect(static fn(): Service => $client->services()->removeStatusOverride($two->id))->toThrow(NotFoundException::class);
            });
        } finally {
            $cleanupFailures = $journey->step('5 cleanup: the resolved incident and the cancelled window', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
