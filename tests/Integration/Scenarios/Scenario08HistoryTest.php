<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\CheckResult;
use LIVCK\Cloud\Data\Incident;
use LIVCK\Cloud\Data\Maintenance;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Enums\CheckResultStatus;
use LIVCK\Cloud\Enums\IncidentKind;
use LIVCK\Cloud\Enums\IncidentSeverity;
use LIVCK\Cloud\Enums\IncidentStatus;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Enums\MaintenanceType;
use LIVCK\Cloud\Enums\MetricsRange;
use LIVCK\Cloud\Enums\UptimeDayStatus;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Pagination\CursorPage;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Query\CheckQuery;
use LIVCK\Cloud\Query\IncidentQuery;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Query\ServiceIncidentQuery;
use LIVCK\Cloud\Support\Dates;
use LIVCK\Cloud\Support\Path;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 8, history: the check history of an HTTP service (cursor pages, filters), its
 * figures (metrics, uptime days, response times), and incidents and maintenance windows
 * created through the escape hatch and read back through the resources.
 */
$skip = LiveApi::skipReason();

describe('scenario 8: history', function () use ($skip): void {
    it('pages the checks, reads the figures and lists incidents and maintenances', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $cleanupFailures = [];

        try {
            $http = Scenario::service($client, $cleanup, ServiceBuilder::http(Scenario::name('hist-http'), 'https://example.com/')->interval(30)->probes('ffm', 'hel', 'nbg'));
            $manual1 = Scenario::manualService($client, $cleanup, 'hist-m1');
            $manual2 = Scenario::manualService($client, $cleanup, 'hist-m2');

            $journey->step('1 check results appear (polled, at most 180 s)', function () use ($client, $http, $journey): void {
                [$page, $elapsed] = Scenario::waitFor(180, static function () use ($client, $http): ?CursorPage {
                    $page = $client->services()->checks($http->id, CheckQuery::make()->withPerPage(100));

                    return count($page) >= 3 ? $page : null;
                }, 5.0);

                $journey->note(sprintf('%d results after %.0f s', $page instanceof CursorPage ? count($page) : 0, $elapsed));

                expect($page)->toBeInstanceOf(CursorPage::class, 'No check results within 180 s.');
            });

            $journey->step('2 checks(): one per page over three cursor pages; eachCheck() walks them all', function () use ($client, $http, $journey): void {
                $all = DtoAudit::inspect($client->services()->checks($http->id, CheckQuery::make()->withPerPage(100)), 'services.checks');
                $first = DtoAudit::inspect($client->services()->checks($http->id, CheckQuery::make()->withPerPage(1)), 'services.checks (per page 1)');
                $second = $first->next();
                $third = $second?->next();
                $key = static fn(CheckResult $check): string => $check->checkedAt->format('Y-m-d\TH:i:s.v') . '@' . $check->probe;

                expect($first->perPage)->toBe(1)
                    ->and(count($first))->toBe(1)
                    ->and($first->hasMore())->toBeTrue()
                    ->and($first->nextCursor)->not->toBeNull()
                    ->and($second)->toBeInstanceOf(CursorPage::class)
                    ->and($third)->toBeInstanceOf(CursorPage::class)
                    ->and($second === null ? 0 : count($second))->toBe(1)
                    ->and($third === null ? 0 : count($third))->toBe(1);

                $keys = array_map($key, array_merge($first->items, $second->items ?? [], $third->items ?? []));

                expect(array_unique($keys))->toHaveCount(3)
                    ->and($keys)->toBe(array_map($key, array_slice($all->items, 0, 3)));

                $walked = 0;

                foreach ($client->services()->eachCheck($http->id, CheckQuery::make()->withPerPage(2)) as $check) {
                    expect($check)->toBeInstanceOf(CheckResult::class);
                    $walked++;
                }

                expect($walked)->toBeGreaterThanOrEqual(count($all));

                $check = $all->items[0];

                expect($check->passed())->toBeTrue()
                    ->and($check->status)->toBe(CheckResultStatus::Up)
                    ->and($check->probe)->toBeIn(['ffm', 'hel', 'nbg'])
                    ->and($check->statusCode)->toBe(200)
                    ->and($check->responseTimeMs)->not->toBeNull()
                    ->and($check->errorMessage)->toBeNull()
                    ->and($check->timings->isMeasured())->toBeTrue()
                    ->and($check->checkedAt->getTimezone()->getName())->toBe('UTC')
                    ->and($check->checkedAt->getTimestamp())->toBeGreaterThan(time() - 900);
                $counter = count($all);

                // Newest first.
                for ($i = 1; $i < $counter; $i++) {
                    expect($all->items[$i]->checkedAt <= $all->items[$i - 1]->checkedAt)->toBeTrue();
                }

                $journey->note(sprintf('%d results in total, eachCheck() walked %d in pages of 2', count($all), $walked));
            });

            $journey->step('3 checks(): probe, status, from and to filters', function () use ($client, $http): void {
                $ffm = $client->services()->checks($http->id, CheckQuery::make()->withProbes('ffm')->withPerPage(100));
                $up = $client->services()->checks($http->id, CheckQuery::make()->withStatuses(CheckResultStatus::Up)->withPerPage(100));
                $down = $client->services()->checks($http->id, CheckQuery::make()->withStatuses(CheckResultStatus::Down, CheckResultStatus::Degraded)->withPerPage(100));
                $window = $client->services()->checks($http->id, CheckQuery::make()->withFrom(new DateTimeImmutable('-1 hour'))->withTo(new DateTimeImmutable('+1 minute'))->withPerPage(100));
                $future = $client->services()->checks($http->id, CheckQuery::make()->withFrom(new DateTimeImmutable('+1 hour'))->withPerPage(100));
                $nowhere = $client->services()->checks($http->id, CheckQuery::make()->withProbes('zzz')->withPerPage(100));

                expect($ffm->isEmpty())->toBeFalse()
                    ->and(array_unique(array_map(static fn(CheckResult $check): string => $check->probe, $ffm->items)))->toBe(['ffm'])
                    ->and($up->isEmpty())->toBeFalse()
                    ->and(array_unique(array_map(static fn(CheckResult $check): string => $check->status->value, $up->items)))->toBe(['up'])
                    ->and($down->isEmpty())->toBeTrue()
                    ->and(count($window))->toBeGreaterThanOrEqual(3)
                    ->and($future->isEmpty())->toBeTrue()
                    ->and($future->nextCursor)->toBeNull()
                    ->and($nowhere->isEmpty())->toBeTrue()
                    ->and($nowhere->hasMore())->toBeFalse();

                expect(static fn(): CheckQuery => CheckQuery::make()->withFrom(new DateTimeImmutable('+1 hour'))->withTo(new DateTimeImmutable('-1 hour')))->toThrow(InvalidArgumentException::class);
                expect(static fn(): CheckQuery => CheckQuery::make()->withCursor(' '))->toThrow(InvalidArgumentException::class);
            });

            $journey->step('4 metrics(), uptime() and responseTimes()', function () use ($client, $http, $journey): void {
                // Availability is computed from incident windows and starts once the service has
                // been observed (`last_check_at`). That mark is written by a separate consumer and
                // can trail the check store under load, so wait for it before expecting 100 %.
                [$observed, $elapsed] = Scenario::waitFor(120, static function () use ($client, $http): ?Service {
                    $service = $client->services()->get($http->id);

                    return $service->lastCheckAt instanceof DateTimeImmutable ? $service : null;
                }, 3.0);

                expect($observed)->toBeInstanceOf(Service::class, 'The service was never marked as observed within 120 s.');
                $journey->note(sprintf('observed after %.0f s', $elapsed));

                $metrics = DtoAudit::inspect($client->services()->metrics($http->id), 'services.metrics');
                $hour = DtoAudit::inspect($client->services()->metrics($http->id, MetricsRange::OneHour), 'services.metrics (1h)');
                $days = DtoAudit::inspect($client->services()->uptime($http->id, 30), 'services.uptime');
                $week = $client->services()->uptime($http->id, 7);
                $points = DtoAudit::inspect($client->services()->responseTimes($http->id, MetricsRange::SixHours), 'services.responseTimes');

                expect($metrics->range)->toBe(MetricsRange::TwentyFourHours)
                    ->and($metrics->hasData())->toBeTrue()
                    ->and($metrics->totalChecks)->toBeGreaterThanOrEqual(3)
                    ->and($metrics->failedChecks)->toBe(0)
                    ->and($metrics->uptime)->toBe(100.0)
                    ->and($metrics->avgMs)->not->toBeNull()
                    ->and($hour->range)->toBe(MetricsRange::OneHour)
                    ->and($hour->totalChecks)->toBeGreaterThanOrEqual(3)
                    ->and($days)->toHaveCount(30)
                    ->and($week)->toHaveCount(7);
                $counter = count($days);

                for ($i = 1; $i < $counter; $i++) {
                    expect($days[$i]->date->isAfter($days[$i - 1]->date))->toBeTrue('uptime days are oldest first');
                }

                $withData = array_values(array_filter($days, static fn($day): bool => $day->hasData()));
                $withoutData = array_values(array_filter($days, static fn($day): bool => ! $day->hasData()));

                expect($withoutData)->not->toBe([])
                    ->and($withoutData[0]->status)->toBe(UptimeDayStatus::NoData)
                    ->and($withoutData[0]->uptime())->toBeNull()
                    ->and($withoutData[0]->uptimePercent)->toBe(0.0);

                if ($withData !== []) {
                    expect($withData[0]->uptime())->not->toBeNull()
                        ->and($withData[0]->incidents)->toBe(0);
                }

                foreach ($points as $point) {
                    expect($point->timestamp->getTimezone()->getName())->toBe('UTC');
                }

                expect(static fn(): array => $client->services()->uptime($http->id, 0))->toThrow(InvalidArgumentException::class);
                expect(static fn(): array => $client->services()->uptime($http->id, 91))->toThrow(InvalidArgumentException::class);

                $journey->note(sprintf(
                    '24h: %d checks, uptime %s, avg %s ms; uptime days with data: %d of 30; response-time points (6h): %d',
                    $metrics->totalChecks,
                    $metrics->uptime === null ? 'null' : (string) $metrics->uptime,
                    $metrics->avgMs === null ? 'null' : (string) round($metrics->avgMs),
                    count($withData),
                    count($points),
                ));
            });

            /** @var array{0: Incident, 1: Incident} $incidents */
            $incidents = $journey->step('5 two incidents through the escape hatch, listed by service ids, resolved, fetched', function () use ($client, $cleanup, $http, $manual1, $manual2): array {
                $incident = static function (string $suffix, string $severity, Service ...$services) use ($cleanup, $client): Incident {
                    $incident = DtoAudit::inspect(Incident::fromArray(Scenario::post($client, 'incidents', [
                        'title' => Scenario::name($suffix),
                        'message' => 'Investigating; created by the SDK scenario suite.',
                        'severity' => $severity,
                        'status' => 'investigating',
                        'is_published' => false,
                        'notify_subscribers' => false,
                        'service_ids' => Scenario::ids(array_values($services)),
                    ])), 'incident (escape hatch)');
                    $cleanup->add('incident ' . $incident->title, static function () use ($client, $incident): void {
                        $client->request('DELETE', Path::join('incidents', $incident->id));
                    });

                    return $incident;
                };

                $one = $incident('incident-1', 'major', $http, $manual1);
                $two = $incident('incident-2', 'minor', $manual2);

                expect($one->status)->toBe(IncidentStatus::Investigating)
                    ->and($one->isOpen())->toBeTrue()
                    ->and($one->kind)->toBe(IncidentKind::Standard)
                    ->and($one->isNotice())->toBeFalse()
                    ->and($one->severity)->toBe(IncidentSeverity::Major)
                    ->and($one->isPublished)->toBeFalse()
                    ->and($one->resolvedAt)->toBeNull()
                    ->and($one->serviceIds())->toEqualCanonicalizing([$http->id, $manual1->id])
                    ->and($one->updates)->toBeArray()
                    ->and($one->attachments)->toBe([])
                    ->and($two->severity)->toBe(IncidentSeverity::Minor)
                    ->and($two->serviceIds())->toBe([$manual2->id]);

                $ids = Scenario::ids(...);
                $ofHttp = DtoAudit::inspect($client->incidents()->list(IncidentQuery::make()->withServiceIds([$http])), 'incidents.list');
                $ofManual2 = $client->incidents()->list(IncidentQuery::make()->withServiceIds([$manual2->id]));
                $both = $client->incidents()->list(IncidentQuery::make()->withServiceIds([$http, $manual2])->withResolved(false)->withKind(IncidentKind::Standard));
                $none = $client->incidents()->list(IncidentQuery::make()->withServiceIds([]));
                $internal = $client->incidents()->list(IncidentQuery::make()->withServiceIds([$http, $manual2])->withPublished(false));
                $published = $client->incidents()->list(IncidentQuery::make()->withServiceIds([$http, $manual2])->withPublished(true));
                $window = $client->incidents()->list(IncidentQuery::make()->withServiceIds([$http, $manual2])->withFrom(new DateTimeImmutable('-1 hour'))->withTo(new DateTimeImmutable('+1 hour')));
                $past = $client->incidents()->list(IncidentQuery::make()->withServiceIds([$http, $manual2])->withTo(new DateTimeImmutable('-1 hour')));
                $notices = $client->incidents()->list(IncidentQuery::make()->withServiceIds([$http, $manual2])->withKind(IncidentKind::Notice));

                expect($ids($ofHttp))->toBe([$one->id])
                    ->and($ofHttp->first()?->services)->not->toBeNull()
                    ->and($ofHttp->first()?->updates)->toBeNull()
                    ->and($ids($ofManual2))->toBe([$two->id])
                    ->and($ids($both))->toEqualCanonicalizing([$one->id, $two->id])
                    ->and($none->total)->toBe(0)
                    ->and($none->isEmpty())->toBeTrue()
                    ->and($ids($internal))->toEqualCanonicalizing([$one->id, $two->id])
                    ->and($published->total)->toBe(0)
                    ->and($ids($window))->toEqualCanonicalizing([$one->id, $two->id])
                    ->and($past->total)->toBe(0)
                    ->and($notices->total)->toBe(0);

                $walked = [];

                foreach ($client->incidents()->each(IncidentQuery::make()->withServiceIds([$http, $manual2])->withPerPage(1)) as $listed) {
                    $walked[] = $listed->id;
                }

                expect($walked)->toEqualCanonicalizing([$one->id, $two->id]);

                $viaService = DtoAudit::inspect($client->services()->incidents($http->id, ServiceIncidentQuery::make()->withResolved(false)), 'services.incidents');
                $impact = $viaService->first()?->serviceImpact;

                expect($ids($viaService))->toBe([$one->id])
                    ->and($impact)->not->toBeNull()
                    ->and($impact?->isRecovered)->toBeFalse()
                    ->and($impact?->recoveredAt)->toBeNull()
                    ->and($client->services()->incidents($manual2->id)->total)->toBe(1);

                $resolved = $client->request('POST', Path::join('incidents', $one->id, 'resolve'), json: ['message' => 'Fixed.', 'notify_subscribers' => false]);
                $fetched = DtoAudit::inspect($client->incidents()->get($one->id), 'incidents.get');

                expect($resolved->status())->toBe(200)
                    ->and($fetched->isResolved())->toBeTrue()
                    ->and($fetched->status)->toBe(IncidentStatus::Resolved)
                    ->and($fetched->resolvedAt)->not->toBeNull()
                    ->and($fetched->updates)->not->toBeNull()
                    ->and(count($fetched->updates ?? []))->toBeGreaterThanOrEqual(2)
                    ->and($fetched->services)->toHaveCount(2)
                    ->and($ids($client->incidents()->list(IncidentQuery::make()->withServiceIds([$http, $manual2])->withResolved(true))))->toBe([$one->id])
                    ->and($ids($client->incidents()->list(IncidentQuery::make()->withServiceIds([$http, $manual2])->withResolved(false))))->toBe([$two->id]);

                foreach ($fetched->updates ?? [] as $update) {
                    expect($update->message)->not->toBe('')
                        ->and($update->notifySubscribers)->toBeFalse();
                }

                return [$one, $two];
            });

            $journey->step('6 a maintenance window through the escape hatch, listed and fetched', function () use ($client, $cleanup, $manual1, $manual2): void {
                $data = Scenario::post($client, 'maintenances', [
                    'title' => Scenario::name('maint-1'),
                    'message' => 'Planned work; created by the SDK scenario suite.',
                    'type' => 'planned',
                    'scheduled_start' => Dates::format(new DateTimeImmutable('+1 hour')),
                    'scheduled_end' => Dates::format(new DateTimeImmutable('+2 hours')),
                    'service_ids' => [$manual1->id],
                    'auto_start' => false,
                    'auto_complete' => false,
                    'notify_announcement' => false,
                    'notify_24h' => false,
                    'notify_1h' => false,
                    'notify_start' => false,
                    'notify_complete' => false,
                ]);
                $window = DtoAudit::inspect(Maintenance::fromArray($data), 'maintenance (escape hatch)');
                $cleanup->add('maintenance ' . $window->title, static function () use ($client, $window): void {
                    $client->request('DELETE', Path::join('maintenances', $window->id));
                });

                expect($window->type)->toBe(MaintenanceType::Planned)
                    ->and($window->status)->toBe(MaintenanceStatus::Scheduled)
                    ->and($window->isActive())->toBeTrue()
                    ->and($window->isOpenEnded())->toBeFalse()
                    ->and($window->autoStart)->toBeFalse()
                    ->and($window->autoComplete)->toBeFalse()
                    ->and($window->serviceIds())->toBe([$manual1->id])
                    ->and($window->scheduledEnd?->getTimestamp())->toBeGreaterThan($window->scheduledStart->getTimestamp());

                $ids = Scenario::ids(...);
                $ofManual1 = DtoAudit::inspect($client->maintenances()->list(MaintenanceQuery::make()->withServiceIds([$manual1])), 'maintenances.list');
                $ofManual2 = $client->maintenances()->list(MaintenanceQuery::make()->withServiceIds([$manual2->id]));
                $none = $client->maintenances()->list(MaintenanceQuery::make()->withServiceIds([]));
                $scheduled = $client->maintenances()->list(MaintenanceQuery::make()->withServiceIds([$manual1])->withStatuses(MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress));
                $completed = $client->maintenances()->list(MaintenanceQuery::make()->withServiceIds([$manual1])->withStatuses(MaintenanceStatus::Completed));
                $upcoming = $client->maintenances()->list(MaintenanceQuery::make()->withServiceIds([$manual1])->withFrom(new DateTimeImmutable('now')));
                $startedBeforeNow = $client->maintenances()->list(MaintenanceQuery::make()->withServiceIds([$manual1])->withTo(new DateTimeImmutable('now')));
                $viaService = DtoAudit::inspect($client->services()->maintenances($manual1->id, MaintenanceQuery::make()->withStatuses(MaintenanceStatus::Scheduled)), 'services.maintenances');

                expect($ids($ofManual1))->toBe([$window->id])
                    ->and($ofManual1->first()?->services)->not->toBeNull()
                    ->and($ofManual1->first()?->statuspages)->toBeNull()
                    ->and($ofManual2->total)->toBe(0)
                    ->and($none->total)->toBe(0)
                    ->and($ids($scheduled))->toBe([$window->id])
                    ->and($completed->total)->toBe(0)
                    ->and($ids($upcoming))->toBe([$window->id])
                    ->and($startedBeforeNow->total)->toBe(0)
                    ->and($ids($viaService))->toBe([$window->id])
                    ->and($client->services()->maintenances($manual2->id)->total)->toBe(0);

                $walked = [];

                foreach ($client->maintenances()->each(MaintenanceQuery::make()->withServiceIds([$manual1])->withPerPage(1)) as $maintenance) {
                    $walked[] = $maintenance->id;
                }

                $fetched = DtoAudit::inspect($client->maintenances()->get($window->id), 'maintenances.get');

                expect($walked)->toBe([$window->id])
                    ->and($fetched->statuspages)->toBe([])
                    ->and($fetched->serviceIds())->toBe([$manual1->id])
                    ->and($fetched->updates)->toBeArray()
                    ->and($fetched->title)->toBe($window->title);

                expect(static fn(): Page => $client->services()->maintenances($manual1->id, MaintenanceQuery::make()->withServiceIds([$manual1])))->toThrow(InvalidArgumentException::class);
            });
        } finally {
            $cleanupFailures = $journey->step('7 cleanup: incidents, maintenance, services', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
