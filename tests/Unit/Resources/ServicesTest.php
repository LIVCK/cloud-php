<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\CheckResult;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\Incident;
use LIVCK\Cloud\Data\Maintenance;
use LIVCK\Cloud\Data\ResponseTimePoint;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\ServiceMetrics;
use LIVCK\Cloud\Data\ServiceSettings;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Data\UptimeDay;
use LIVCK\Cloud\Enums\CheckResultStatus;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\IncidentKind;
use LIVCK\Cloud\Enums\IpVersion;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Enums\MetricsRange;
use LIVCK\Cloud\Enums\PausedReason;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Enums\ServiceImpact;
use LIVCK\Cloud\Enums\ServiceStatus;
use LIVCK\Cloud\Enums\StatusOverride;
use LIVCK\Cloud\Enums\UptimeDayStatus;
use LIVCK\Cloud\Exceptions\CatalogValidationException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\PlanLimitException;
use LIVCK\Cloud\Exceptions\ServiceUnavailableException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\CursorPage;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Query\CheckQuery;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Query\ServiceIncidentQuery;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Support\KeepSecret;
use LIVCK\Cloud\Support\Uuid;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use LIVCK\Cloud\Tests\Fixtures\CatalogFixture;
use LIVCK\Cloud\Tests\Fixtures\CheckFixtures;
use LIVCK\Cloud\Tests\Fixtures\IncidentFixtures;
use LIVCK\Cloud\Tests\Fixtures\MaintenanceFixtures;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;

describe('list', function (): void {
    it('lists services as a page of hydrated DTOs', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([ServiceFixtures::payload(), ServiceFixtures::unconfigured()])]);

        $page = $client->services()->list();
        $service = $page->items[0];
        $tags = $service->tags ?? [];

        expect($page)->toBeInstanceOf(Page::class)
            ->and($page->total)->toBe(2)
            ->and($service)->toBeInstanceOf(Service::class)
            ->and($service->id)->toBe(ServiceFixtures::ID)
            ->and($service->name)->toBe('API health')
            ->and($service->checkType)->toBe(CheckType::Http)
            ->and($service->checkTypeLabel)->toBe('HTTP/HTTPS')
            ->and($service->target)->toBe('https://api.example.com/health')
            ->and($service->status)->toBe(ServiceStatus::Up)
            ->and($service->effectiveStatus)->toBe(ServiceStatus::Up)
            ->and($service->statusOverride)->toBeNull()
            ->and($service->statusOverrideReason)->toBeNull()
            ->and($service->statusOverrideAt)->toBeNull()
            ->and($service->hasStatusOverride())->toBeFalse()
            ->and($service->faviconUrl)->toBeNull()
            ->and($service->isPaused)->toBeFalse()
            ->and($service->pausedReason)->toBeNull()
            ->and($service->configuredAt?->format(DATE_ATOM))->toBe('2026-08-14T02:40:55+00:00')
            ->and($service->isConfigured)->toBeTrue()
            ->and($service->uptime30d)->toBe(99.98)
            ->and($service->avgResponseMs)->toBe(142.5)
            ->and($tags)->toHaveCount(1)
            ->and($tags[0])->toBeInstanceOf(Tag::class)
            ->and($tags[0]->label)->toBe('kunde:4711')
            ->and($tags[0]->servicesCount)->toBeNull()
            ->and($service->tagIds())->toBe([ServiceFixtures::TAG_ID])
            ->and($service->hasTag('kunde:4711'))->toBeTrue()
            ->and($service->hasTag('kunde:4712'))->toBeFalse()
            ->and($service->lastCheckAt?->format(DATE_ATOM))->toBe('2026-09-26T05:01:35+00:00')
            ->and($service->createdAt->format(DATE_ATOM))->toBe('2026-08-14T02:40:55+00:00')
            ->and($service->isMonitoredByProbes())->toBeTrue()
            ->and($service->raw)->toBe(ServiceFixtures::payload());

        $settings = $service->settings;

        expect($settings)->toBeInstanceOf(ServiceSettings::class)
            ->and($settings?->intervalSeconds)->toBe(60)
            ->and($settings?->timeoutSeconds)->toBe(10)
            ->and($settings?->retries)->toBe(2)
            ->and($settings?->assignedProbes)->toBe(['ffm', 'hel'])
            ->and($settings?->inheritsProbes())->toBeFalse()
            ->and($settings?->probeRoles)->toBe(['hel' => ProbeRole::Reachability])
            ->and($settings?->value('method'))->toBe('GET')
            ->and($settings?->headers())->toBe(['X-Api-Key' => KeepSecret::SENTINEL])
            ->and($settings?->conditions()[0]->field)->toBe('status_code')
            ->and($settings?->conditions()[0]->value)->toBe(400);

        $unconfigured = $page->items[1];

        expect($unconfigured->isConfigured)->toBeFalse()
            ->and($unconfigured->settings)->toBeNull()
            ->and($unconfigured->configuredAt)->toBeNull()
            ->and($unconfigured->tags)->toBe([])
            ->and($unconfigured->uptime30d)->toBeNull();

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/services') && $r->queryString() === '');
    });

    it('sends the tag filter and paging of a query', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([])]);

        $client->services()->list(ServiceQuery::make()->withTag('kunde:4711')->withPage(2)->withPerPage(10));

        expect($http->lastRequest()?->query())->toBe(['tag' => 'kunde:4711', 'page' => '2', 'per_page' => '10']);
    });

    it('keeps unknown enum values readable', function (): void {
        [$client] = fakeClient([MockResponse::page([ServiceFixtures::payload([
            'check_type' => 'quantum',
            'status' => 'hibernating',
            'effective_status' => 'hibernating',
            'status_override' => 'frozen',
            'paused_reason' => 'weather',
            'settings' => ServiceFixtures::settings(['probe_roles' => ['ffm' => 'observer']]),
        ])])]);

        $service = $client->services()->list()->first();

        expect($service?->checkType)->toBe(CheckType::Unrecognized)
            ->and($service?->status)->toBe(ServiceStatus::Unrecognized)
            ->and($service?->effectiveStatus)->toBe(ServiceStatus::Unrecognized)
            ->and($service?->statusOverride)->toBe(StatusOverride::Unrecognized)
            ->and($service?->pausedReason)->toBe(PausedReason::Unrecognized)
            ->and($service?->settings?->probeRoles)->toBe(['ffm' => ProbeRole::Unrecognized])
            ->and($service?->raw['status'])->toBe('hibernating');
    });

    it('treats an empty headers list as no headers', function (): void {
        [$client] = fakeClient([MockResponse::page([ServiceFixtures::payload([
            'settings' => ServiceFixtures::settings(['config' => ['method' => 'GET', 'headers' => [], 'conditions' => []]]),
        ])])]);

        $settings = $client->services()->list()->first()?->settings;

        expect($settings?->headers())->toBe([])
            ->and($settings?->conditions())->toBe([]);
    });
});

describe('each', function (): void {
    it('yields every service across all pages with the same filters', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::page([ServiceFixtures::payload(['id' => 'a' . str_repeat('0', 20)])], currentPage: 1, lastPage: 2, perPage: 1),
            MockResponse::page([ServiceFixtures::payload(['id' => 'b' . str_repeat('0', 20)])], currentPage: 2, lastPage: 2, perPage: 1),
        ]);

        $ids = [];

        foreach ($client->services()->each(ServiceQuery::make()->withTag('kunde:4711')->withPerPage(1)) as $service) {
            $ids[] = $service->id;
        }

        expect($ids)->toBe(['a' . str_repeat('0', 20), 'b' . str_repeat('0', 20)])
            ->and($http->recorded()[1]->query())->toBe(['tag' => 'kunde:4711', 'page' => '2', 'per_page' => '1']);
    });
});

describe('get', function (): void {
    it('fetches one service by id, percent-encoded in the path', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => ServiceFixtures::payload()])]);

        $service = $client->services()->get('odd/id');

        expect($service->id)->toBe(ServiceFixtures::ID)
            ->and($http->lastRequest()?->uri)->toBe('https://api.livck.cloud/v1/services/odd%2Fid');
    });

    it('raises not found', function (): void {
        [$client] = singleShotClient([MockResponse::error('The requested resource was not found.', 404)]);

        expect(fn(): Service => $client->services()->get('missing'))->toThrow(NotFoundException::class);
    });
});

describe('create', function (): void {
    it('posts exactly the builder body and hydrates the created service', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => ServiceFixtures::payload()], 201)]);

        $builder = ServiceBuilder::http('Shop', 'https://shop.example.com/health')
            ->interval(30)
            ->timeout(10)
            ->retries(2)
            ->probes('ffm', 'hel')
            ->probeRole('hel', ProbeRole::Reachability)
            ->tags(ServiceFixtures::TAG_ID)
            ->method(HttpMethod::Post)
            ->header('X-Api-Key', 'k1')
            ->auth(HttpAuth::bearer('t0k'))
            ->body('{}')
            ->followRedirects(false)
            ->verifySsl(false)
            ->ipVersion(IpVersion::Ipv4)
            ->smartDualstack()
            ->condition(
                HttpCondition::statusCode()->gte(500)->down(),
                HttpCondition::responseTimeMs()->gt(2000)->degraded(),
            );

        $service = $client->services()->create($builder);

        expect($service->id)->toBe(ServiceFixtures::ID);

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/services') && $r->idempotencyKey() !== null);

        expect($http->lastRequest()?->json())->toBe([
            'name' => 'Shop',
            'check_type' => 'http',
            'target' => 'https://shop.example.com/health',
            'tags' => [ServiceFixtures::TAG_ID],
            'settings' => [
                'interval_seconds' => 30,
                'timeout_seconds' => 10,
                'retries' => 2,
                'assigned_probes' => ['ffm', 'hel'],
                'probe_roles' => ['hel' => 'reachability'],
                'config' => [
                    'method' => 'POST',
                    'headers' => ['X-Api-Key' => 'k1'],
                    'auth' => ['type' => 'bearer', 'token' => 't0k'],
                    'body' => '{}',
                    'follow_redirects' => false,
                    'verify_ssl' => false,
                    'ip_version' => 'ipv4',
                    'smart_dualstack' => true,
                    'conditions' => [
                        ['field' => 'status_code', 'operator' => 'gte', 'value' => 500, 'status' => 'down'],
                        ['field' => 'response_time_ms', 'operator' => 'gt', 'value' => 2000, 'status' => 'degraded'],
                    ],
                ],
            ],
        ]);
    });

    it('always sends settings with an interval so the service is monitored from the start', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => ServiceFixtures::payload()], 201)]);

        $client->services()->create(ServiceBuilder::icmp('Gateway', 'gw.example.net'));

        expect($http->lastRequest()?->json())->toBe([
            'name' => 'Gateway',
            'check_type' => 'icmp',
            'target' => 'gw.example.net',
            'settings' => ['interval_seconds' => 60],
        ]);
    });

    it('validates against a catalog before sending and refuses a payload that does not match', function (): void {
        [$client, $http] = fakeClient();
        $catalog = CheckTypeCatalog::fromArray(CatalogFixture::data());

        $builder = ServiceBuilder::http('Shop', 'https://shop.example.com')->config('retry_budget', 3);

        expect(fn(): Service => $client->services()->create($builder, $catalog))
            ->toThrow(CatalogValidationException::class, 'retry_budget');
        $http->assertNothingSent();
    });

    it('sends a payload that matches the catalog', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => ServiceFixtures::payload()], 201)]);
        $catalog = CheckTypeCatalog::fromArray(CatalogFixture::data());

        $service = $client->services()->create(ServiceBuilder::http('Shop', 'https://shop.example.com')->condition(HttpCondition::body()->contains('OK')), $catalog);

        $http->assertSentCount(1);

        expect($service->id)->toBe(ServiceFixtures::ID);
    });

    it('surfaces the plan limit on services', function (): void {
        [$client] = singleShotClient([MockResponse::error(
            'Your plan\'s service limit has been reached.',
            403,
            extra: ['upsell' => ['reason' => 'limit', 'key' => 'services'], 'limit' => 50, 'usage' => 50],
        )]);

        try {
            $client->services()->create(ServiceBuilder::manual('Phone'));
            expect(false)->toBeTrue('a PlanLimitException was expected');
        } catch (PlanLimitException $e) {
            expect($e->limitKey())->toBe('services')
                ->and($e->limit())->toBe(50)
                ->and($e->usage())->toBe(50);
        }
    });

    it('surfaces an interval below the plan floor as a validation error on the field', function (): void {
        [$client] = singleShotClient([MockResponse::error(
            'The check interval is below the minimum allowed by your plan or this check type.',
            422,
            ['settings.interval_seconds' => ['The check interval is below the minimum allowed by your plan or this check type.']],
        )]);

        try {
            $client->services()->create(ServiceBuilder::http('Shop', 'https://shop.example.com')->interval(5));
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->hasError('settings.interval_seconds'))->toBeTrue();
        }
    });

    it('sends the given idempotency key instead of a generated one', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => ServiceFixtures::payload()], 201)]);

        $client->services()->create(ServiceBuilder::manual('Phone'), idempotencyKey: 'order-4711-phone');

        expect($http->lastRequest()?->idempotencyKey())->toBe('order-4711-phone');
    });

    it('generates a UUID v4 key when none is given', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => ServiceFixtures::payload()], 201)]);

        $client->services()->create(ServiceBuilder::manual('Phone'));

        expect(Uuid::isV4((string) $http->lastRequest()?->idempotencyKey()))->toBeTrue();
    });

    it('refuses a malformed idempotency key before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): Service => $client->services()->create(ServiceBuilder::manual('Phone'), idempotencyKey: 'has space'))
            ->toThrow(InvalidArgumentException::class, 'visible ASCII');
        $http->assertNothingSent();
    });
});

describe('update', function (): void {
    it('patches only what was set', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => ServiceFixtures::payload(['name' => 'Renamed'])])]);

        $service = $client->services()->update(ServiceFixtures::ID, UpdateService::make()->withName('Renamed')->withRetries(4));

        expect($service->name)->toBe('Renamed');
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('PATCH', '/v1/services/' . ServiceFixtures::ID)
            && $r->json() === ['name' => 'Renamed', 'settings' => ['retries' => 4]]
            && ! $r->hasHeader('Idempotency-Key'));
    });

    it('refuses an empty update before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): Service => $client->services()->update(ServiceFixtures::ID, UpdateService::make()))->toThrow(InvalidArgumentException::class, 'no changes');
        $http->assertNothingSent();
    });

    it('surfaces a synced service as a validation error', function (): void {
        [$client] = singleShotClient([MockResponse::error('This service is managed by a sync source and cannot be edited via the API.', 422)]);

        expect(fn(): Service => $client->services()->update(ServiceFixtures::ID, UpdateService::make()->withName('x')))->toThrow(ValidationException::class);
    });
});

describe('delete', function (): void {
    it('deletes without flags by default', function (): void {
        [$client, $http] = fakeClient([MockResponse::noContent()]);

        $client->services()->delete(ServiceFixtures::ID);

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('DELETE', '/v1/services/' . ServiceFixtures::ID) && $r->queryString() === '' && $r->body === '');

        expect($http->recorded())->toHaveCount(1)
            ->and($http->lastRequest()?->hasHeader('Idempotency-Key'))->toBeFalse();
    });

    it('sends the orphan flags as query booleans', function (): void {
        [$client, $http] = fakeClient([MockResponse::noContent()]);

        $client->services()->delete(ServiceFixtures::ID, deleteOrphanedIncidents: true, deleteOrphanedMaintenances: true);

        expect($http->lastRequest()?->query())->toBe(['delete_orphaned_incidents' => '1', 'delete_orphaned_maintenances' => '1']);
    });

    it('surfaces a missing ability for a flag as permission denied', function (): void {
        [$client] = singleShotClient([MockResponse::error("This token lacks the required 'incidents.delete' permission.", 403)]);

        expect(fn() => $client->services()->delete(ServiceFixtures::ID, deleteOrphanedIncidents: true))->toThrow(PermissionDeniedException::class);
    });
});

describe('pause and resume', function (): void {
    it('pauses with an empty POST and hydrates the paused service', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => ServiceFixtures::payload(['is_paused' => true, 'paused_reason' => 'manual', 'status' => 'paused', 'effective_status' => 'paused'])])]);

        $service = $client->services()->pause(ServiceFixtures::ID);

        expect($service->isPaused)->toBeTrue()
            ->and($service->pausedReason)->toBe(PausedReason::Manual)
            ->and($service->status)->toBe(ServiceStatus::Paused);
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/services/' . ServiceFixtures::ID . '/pause') && $r->body === '' && $r->idempotencyKey() !== null);
    });

    it('resumes', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => ServiceFixtures::payload()])]);

        $service = $client->services()->resume(ServiceFixtures::ID);

        expect($service->isPaused)->toBeFalse();
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/services/' . ServiceFixtures::ID . '/resume'));
    });

    it('surfaces a full quota on resume as a plan limit', function (): void {
        [$client] = singleShotClient([MockResponse::error(
            'Your plan\'s service limit has been reached, so this service cannot be resumed.',
            403,
            extra: ['upsell' => ['reason' => 'limit', 'key' => 'services'], 'limit' => 50, 'usage' => 50],
        )]);

        expect(fn(): Service => $client->services()->resume(ServiceFixtures::ID))->toThrow(PlanLimitException::class);
    });
});

describe('status override', function (): void {
    it('applies an override with status and reason', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => ServiceFixtures::payload([
            'status_override' => 'maintenance',
            'status_override_reason' => 'Planned migration',
            'status_override_at' => '2026-09-26T06:00:00+00:00',
            'effective_status' => 'maintenance',
        ])])]);

        $service = $client->services()->applyStatusOverride(ServiceFixtures::ID, StatusOverride::Maintenance, 'Planned migration');

        expect($service->statusOverride)->toBe(StatusOverride::Maintenance)
            ->and($service->statusOverrideReason)->toBe('Planned migration')
            ->and($service->statusOverrideAt?->format(DATE_ATOM))->toBe('2026-09-26T06:00:00+00:00')
            ->and($service->effectiveStatus)->toBe(ServiceStatus::Maintenance)
            ->and($service->hasStatusOverride())->toBeTrue();
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/services/' . ServiceFixtures::ID . '/status-override')
            && $r->json() === ['status' => 'maintenance', 'reason' => 'Planned migration']);
    });

    it('refuses a blank or over-long reason before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): Service => $client->services()->applyStatusOverride(ServiceFixtures::ID, StatusOverride::Down, '  '))->toThrow(InvalidArgumentException::class, 'reason')
            ->and(fn(): Service => $client->services()->applyStatusOverride(ServiceFixtures::ID, StatusOverride::Down, str_repeat('a', 501)))->toThrow(InvalidArgumentException::class, '500');
        $http->assertNothingSent();
    });

    it('removes an override with a DELETE and hydrates the answer', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => ServiceFixtures::payload()])]);

        $service = $client->services()->removeStatusOverride(ServiceFixtures::ID);

        expect($service->statusOverride)->toBeNull();
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('DELETE', '/v1/services/' . ServiceFixtures::ID . '/status-override'));
    });

    it('raises not found when a repeated removal finds the service gone', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::networkError(),
            MockResponse::error('The requested resource was not found.', 404),
        ]);

        expect(fn(): Service => $client->services()->removeStatusOverride('gone'))->toThrow(NotFoundException::class);
        expect($http->recorded())->toHaveCount(2);
    });
});

describe('metrics', function (): void {
    it('reads the range figures and the range the server reported', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(ServiceFixtures::metrics('7d'))]);

        $metrics = $client->services()->metrics(ServiceFixtures::ID, MetricsRange::SevenDays);

        expect($metrics)->toBeInstanceOf(ServiceMetrics::class)
            ->and($metrics->uptime)->toBe(99.95)
            ->and($metrics->avgMs)->toBe(142.5)
            ->and($metrics->p95Ms)->toBe(310.0)
            ->and($metrics->p99Ms)->toBe(512.2)
            ->and($metrics->minMs)->toBe(88.0)
            ->and($metrics->maxMs)->toBe(1204.0)
            ->and($metrics->totalChecks)->toBe(2880)
            ->and($metrics->failedChecks)->toBe(3)
            ->and($metrics->range)->toBe(MetricsRange::SevenDays)
            ->and($metrics->hasData())->toBeTrue();
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/services/' . ServiceFixtures::ID . '/metrics') && $r->query() === ['range' => '7d']);
    });

    it('defaults to the last 24 hours and reads an empty range', function (): void {
        [$client, $http] = fakeClient([MockResponse::json([
            'data' => ['uptime' => null, 'avg_ms' => null, 'p95_ms' => null, 'p99_ms' => null, 'min_ms' => null, 'max_ms' => null, 'total_checks' => 0, 'failed_checks' => 0],
            'meta' => ['range' => '24h'],
        ])]);

        $metrics = $client->services()->metrics(ServiceFixtures::ID);

        expect($metrics->hasData())->toBeFalse()
            ->and($metrics->uptime)->toBeNull()
            ->and($http->lastRequest()?->query())->toBe(['range' => '24h']);
    });
});

describe('uptime', function (): void {
    it('reads calendar days and keeps a day without data apart from an outage', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(ServiceFixtures::uptime())]);

        $days = $client->services()->uptime(ServiceFixtures::ID, 3);

        expect($days)->toHaveCount(3)
            ->and($days[0])->toBeInstanceOf(UptimeDay::class)
            ->and($days[0]->date->toString())->toBe('2026-09-24')
            ->and($days[0]->status)->toBe(UptimeDayStatus::NoData)
            ->and($days[0]->hasData())->toBeFalse()
            ->and($days[0]->uptimePercent)->toBe(0.0)
            ->and($days[0]->uptime())->toBeNull()
            ->and($days[1]->status)->toBe(UptimeDayStatus::Degraded)
            ->and($days[1]->uptime())->toBe(97.5)
            ->and($days[1]->incidents)->toBe(1)
            ->and($days[2]->uptime())->toBe(100.0);
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/services/' . ServiceFixtures::ID . '/uptime') && $r->query() === ['days' => '3']);
    });

    it('refuses days outside 1..90 before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): array => $client->services()->uptime(ServiceFixtures::ID, 0))->toThrow(InvalidArgumentException::class, 'between 1 and 90')
            ->and(fn(): array => $client->services()->uptime(ServiceFixtures::ID, 91))->toThrow(InvalidArgumentException::class, 'between 1 and 90');
        $http->assertNothingSent();
    });
});

describe('responseTimes', function (): void {
    it('reads the trend', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(ServiceFixtures::responseTimes('1h'))]);

        $points = $client->services()->responseTimes(ServiceFixtures::ID, MetricsRange::OneHour);

        expect($points)->toHaveCount(2)
            ->and($points[0])->toBeInstanceOf(ResponseTimePoint::class)
            ->and($points[0]->timestamp->format(DATE_ATOM))->toBe('2026-09-26T04:00:00+00:00')
            ->and($points[0]->avgMs)->toBe(140.2)
            ->and($points[0]->minMs)->toBe(90.0)
            ->and($points[0]->p99Ms)->toBe(405.0)
            ->and($points[1]->avgMs)->toBe(151.7)
            ->and($points[1]->minMs)->toBe(95.0)
            ->and($points[1]->maxMs)->toBe(388.0);
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/services/' . ServiceFixtures::ID . '/response-times') && $r->query() === ['range' => '1h']);
    });

    it('tolerates a point whose numbers come quoted or missing', function (): void {
        [$client] = fakeClient([MockResponse::json([
            'data' => [['timestamp' => '2026-09-26T05:00:00Z', 'avg_ms' => '151.7', 'min_ms' => '95', 'max_ms' => null, 'p95_ms' => 320.1, 'p99_ms' => 480.9]],
            'meta' => ['range' => '1h'],
        ])]);

        $points = $client->services()->responseTimes(ServiceFixtures::ID, MetricsRange::OneHour);

        expect($points)->toHaveCount(1)
            ->and($points[0]->avgMs)->toBe(151.7)
            ->and($points[0]->minMs)->toBe(95.0)
            ->and($points[0]->maxMs)->toBeNull();
    });
});

describe('checks', function (): void {
    it('lists checks as a cursor page of hydrated DTOs', function (): void {
        [$client, $http] = fakeClient([MockResponse::cursorPage([CheckFixtures::failed(), CheckFixtures::payload()], perPage: 50)]);

        $page = $client->services()->checks(ServiceFixtures::ID);
        $failed = $page->items[0];
        $passed = $page->items[1];

        expect($page)->toBeInstanceOf(CursorPage::class)
            ->and($page->hasMore())->toBeFalse()
            ->and($page->perPage)->toBe(50)
            ->and($failed)->toBeInstanceOf(CheckResult::class)
            ->and($failed->checkedAt->format('Y-m-d\TH:i:s.v\Z'))->toBe('2026-09-20T10:01:00.472Z')
            ->and($failed->probe)->toBe('hel')
            ->and($failed->status)->toBe(CheckResultStatus::Down)
            ->and($failed->passed())->toBeFalse()
            ->and($failed->responseTimeMs)->toBe(10000)
            ->and($failed->statusCode)->toBeNull()
            ->and($failed->errorMessage)->toBe('Request timed out')
            ->and($failed->timings->isMeasured())->toBeFalse()
            ->and($failed->timings->total)->toBeNull()
            ->and($passed->passed())->toBeTrue()
            ->and($passed->statusCode)->toBe(200)
            ->and($passed->errorMessage)->toBeNull()
            ->and($passed->timings->dns)->toBe(5.0)
            ->and($passed->timings->ttfb)->toBe(80.0)
            ->and($passed->timings->total)->toBe(120.0)
            ->and($passed->raw)->toBe(CheckFixtures::payload());
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/services/' . ServiceFixtures::ID . '/checks') && $r->queryString() === '');
    });

    it('sends the filters of a query as the server reads them', function (): void {
        [$client, $http] = fakeClient([MockResponse::cursorPage([])]);

        $client->services()->checks(ServiceFixtures::ID, CheckQuery::make()
            ->withProbes('ffm', 'hel')
            ->withStatuses(CheckResultStatus::Down, CheckResultStatus::Degraded)
            ->withFrom(new DateTimeImmutable('2026-09-20 12:00:00', new DateTimeZone('Europe/Berlin')))
            ->withTo(new DateTimeImmutable('2026-09-20T11:00:00Z'))
            ->withPerPage(25));

        expect($http->lastRequest()?->query())->toBe([
            'probe' => ['ffm', 'hel'],
            'status' => ['down', 'degraded'],
            'from' => '2026-09-20T10:00:00Z',
            'to' => '2026-09-20T11:00:00Z',
            'per_page' => '25',
        ]);
    });

    it('walks every page through the cursor, keeping the filters', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::cursorPage([CheckFixtures::payload(['probe' => 'a']), CheckFixtures::payload(['probe' => 'b'])], nextCursor: 'c1', perPage: 2),
            MockResponse::cursorPage([CheckFixtures::payload(['probe' => 'c']), CheckFixtures::payload(['probe' => 'd'])], nextCursor: 'c2', perPage: 2),
            MockResponse::cursorPage([CheckFixtures::payload(['probe' => 'e'])], perPage: 2),
        ]);

        $probes = [];

        foreach ($client->services()->eachCheck(ServiceFixtures::ID, CheckQuery::make()->withStatuses(CheckResultStatus::Up)->withPerPage(2)) as $check) {
            $probes[] = $check->probe;

            if ($check->probe === 'b') {
                expect($http->recorded())->toHaveCount(1);
            }
        }

        expect($probes)->toBe(['a', 'b', 'c', 'd', 'e'])
            ->and($http->recorded())->toHaveCount(3)
            ->and($http->recorded()[0]->query())->toBe(['status' => ['up'], 'per_page' => '2'])
            ->and($http->recorded()[1]->query())->toBe(['status' => ['up'], 'per_page' => '2', 'cursor' => 'c1'])
            ->and($http->recorded()[2]->query())->toBe(['status' => ['up'], 'per_page' => '2', 'cursor' => 'c2']);
    });

    it('reads the empty page of a service no probe checks', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => [], 'meta' => ['per_page' => 50, 'next_cursor' => null], 'links' => ['next' => null]])]);

        $page = $client->services()->checks(ServiceFixtures::ID);

        expect($page->isEmpty())->toBeTrue()
            ->and($page->next())->toBeNull();
    });

    it('surfaces a history store that cannot answer as service unavailable with the retry hint', function (): void {
        [$client] = singleShotClient([MockResponse::error('Check history is temporarily unavailable.', 503)->withRetryAfter(30)]);

        try {
            $client->services()->checks(ServiceFixtures::ID);
            expect(false)->toBeTrue('a ServiceUnavailableException was expected');
        } catch (ServiceUnavailableException $e) {
            expect($e->retryAfter())->toBe(30)
                ->and($e->errorMessage())->toBe('Check history is temporarily unavailable.');
        }
    });

    it('surfaces a tampered cursor as a validation error', function (): void {
        [$client] = singleShotClient([MockResponse::error('The cursor is not valid.', 422, ['cursor' => ['The cursor is not valid. Pass `next_cursor` from the previous page back unchanged.']])]);

        expect(fn(): CursorPage => $client->services()->checks(ServiceFixtures::ID, CheckQuery::make()->withCursor('made-up')))->toThrow(ValidationException::class);
    });
});

describe('incidents', function (): void {
    it('lists the incidents of a service with what each meant for it', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([IncidentFixtures::withImpact()])]);

        $page = $client->services()->incidents(ServiceFixtures::ID, ServiceIncidentQuery::make()->withResolved(false)->withPublished()->withKind(IncidentKind::Standard)->withPerPage(5));
        $incident = $page->items[0];

        expect($incident)->toBeInstanceOf(Incident::class)
            ->and($incident->isOpen())->toBeTrue()
            ->and($incident->serviceImpact?->impact)->toBe(ServiceImpact::PartialOutage)
            ->and($incident->serviceImpact?->impact?->countsAsDowntime())->toBeTrue()
            ->and($incident->serviceImpact?->isRecovered)->toBeFalse()
            ->and($incident->serviceImpact?->addedAt?->format(DATE_ATOM))->toBe('2026-09-25T12:00:00+00:00')
            ->and($incident->serviceImpact?->recoveredAt)->toBeNull()
            ->and($incident->updates)->toBeNull();
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/services/' . ServiceFixtures::ID . '/incidents')
            && $r->query() === ['resolved' => '0', 'is_published' => '1', 'kind' => 'standard', 'per_page' => '5']);
    });

    it('follows the pages with the same filters', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::page([IncidentFixtures::withImpact()], currentPage: 1, lastPage: 2, perPage: 1),
            MockResponse::page([IncidentFixtures::payload()], currentPage: 2, lastPage: 2, perPage: 1),
        ]);

        $second = $client->services()->incidents(ServiceFixtures::ID, ServiceIncidentQuery::make()->withPerPage(1))->nextPage();

        expect($second?->items[0]->serviceImpact)->toBeNull()
            ->and($http->recorded()[1]->query())->toBe(['page' => '2', 'per_page' => '1']);
    });
});

describe('maintenances', function (): void {
    it('lists the windows covering a service', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([MaintenanceFixtures::payload()])]);

        $page = $client->services()->maintenances(ServiceFixtures::ID, MaintenanceQuery::make()->withStatuses(MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress));

        expect($page->items[0])->toBeInstanceOf(Maintenance::class)
            ->and($page->items[0]->id)->toBe(MaintenanceFixtures::ID)
            ->and($page->items[0]->serviceIds())->toBe([ServiceFixtures::ID]);
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/services/' . ServiceFixtures::ID . '/maintenances')
            && $r->query() === ['status' => ['scheduled', 'in_progress']]);
    });

    it('refuses a service scope on the service-scoped list before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): Page => $client->services()->maintenances(ServiceFixtures::ID, MaintenanceQuery::make()->withServiceIds([ServiceFixtures::ID])))
            ->toThrow(InvalidArgumentException::class, 'serviceIds');
        $http->assertNothingSent();
    });
});
