<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\Maintenance;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Enums\MaintenanceType;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use LIVCK\Cloud\Tests\Fixtures\MaintenanceFixtures;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;

describe('list', function (): void {
    it('lists windows as a page of hydrated DTOs', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([MaintenanceFixtures::payload()])]);

        $page = $client->maintenances()->list();
        $window = $page->items[0];

        expect($page)->toBeInstanceOf(Page::class)
            ->and($window)->toBeInstanceOf(Maintenance::class)
            ->and($window->id)->toBe(MaintenanceFixtures::ID)
            ->and($window->title)->toBe('Kernel update')
            ->and($window->titleTranslations)->toBeNull()
            ->and($window->type)->toBe(MaintenanceType::Planned)
            ->and($window->status)->toBe(MaintenanceStatus::Scheduled)
            ->and($window->scheduledStart->format(DATE_ATOM))->toBe('2026-10-01T22:00:00+00:00')
            ->and($window->scheduledEnd?->format(DATE_ATOM))->toBe('2026-10-02T00:00:00+00:00')
            ->and($window->autoStart)->toBeTrue()
            ->and($window->autoComplete)->toBeTrue()
            ->and($window->createdAt->format(DATE_ATOM))->toBe('2026-09-20T09:15:00+00:00')
            ->and($window->attachments)->toBe([])
            ->and($window->statuspages)->toBeNull()
            ->and($window->services)->toHaveCount(1)
            ->and($window->serviceIds())->toBe([ServiceFixtures::ID])
            ->and($window->updates)->toBeNull()
            ->and($window->isActive())->toBeTrue()
            ->and($window->isOpenEnded())->toBeFalse()
            ->and($window->raw)->toBe(MaintenanceFixtures::payload());

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/maintenances') && $r->queryString() === '');
    });

    it('sends every filter as the server reads it', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([])]);

        $client->maintenances()->list(MaintenanceQuery::make()
            ->withServiceIds([ServiceFixtures::ID])
            ->withStatuses(MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress)
            ->withFrom(new DateTimeImmutable('2026-10-01T00:00:00Z'))
            ->withTo(new DateTimeImmutable('2026-11-01T00:00:00Z'))
            ->withPage(2)
            ->withPerPage(50));

        expect($http->lastRequest()?->query())->toBe([
            'service_ids' => [ServiceFixtures::ID],
            'status' => ['scheduled', 'in_progress'],
            'from' => '2026-10-01T00:00:00Z',
            'to' => '2026-11-01T00:00:00Z',
            'page' => '2',
            'per_page' => '50',
        ]);
    });

    it('sends an explicitly empty service scope, which matches nothing', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([])]);

        $client->maintenances()->list(MaintenanceQuery::make()->withServiceIds([]));

        expect($http->lastRequest()?->queryString())->toBe('service_ids=');
    });

    it('keeps unknown enum values readable', function (): void {
        [$client] = fakeClient([MockResponse::page([MaintenanceFixtures::payload(['status' => 'postponed', 'type' => 'recurring'])])]);

        $window = $client->maintenances()->list()->first();

        expect($window?->status)->toBe(MaintenanceStatus::Unrecognized)
            ->and($window?->type)->toBe(MaintenanceType::Unrecognized)
            ->and($window?->isActive())->toBeFalse();
    });

    it('surfaces an unknown status as a validation error', function (): void {
        [$client] = singleShotClient([MockResponse::error('Unknown maintenance status.', 422, ['status.0' => ['The selected status is invalid.']])]);

        expect(fn(): Page => $client->maintenances()->list())->toThrow(ValidationException::class);
    });
});

describe('each', function (): void {
    it('yields every window across all pages', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::page([MaintenanceFixtures::payload(['id' => 'a'])], currentPage: 1, lastPage: 2, perPage: 1),
            MockResponse::page([MaintenanceFixtures::payload(['id' => 'b'])], currentPage: 2, lastPage: 2, perPage: 1),
        ]);

        $ids = [];

        foreach ($client->maintenances()->each(MaintenanceQuery::make()->withPerPage(1)) as $window) {
            $ids[] = $window->id;
        }

        expect($ids)->toBe(['a', 'b'])
            ->and($http->recorded()[1]->query())->toBe(['page' => '2', 'per_page' => '1']);
    });
});

describe('get', function (): void {
    it('fetches one window with statuspages and timeline', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => MaintenanceFixtures::detailed()])]);

        $window = $client->maintenances()->get(MaintenanceFixtures::ID);
        $statuspages = $window->statuspages ?? [];
        $updates = $window->updates ?? [];

        expect($window->titleTranslations)->toBe(['de' => 'Kernel-Update', 'en' => 'Kernel update'])
            ->and($statuspages)->toHaveCount(1)
            ->and($statuspages[0]->name)->toBe('Customer status')
            ->and($updates)->toHaveCount(1)
            ->and($updates[0]->status)->toBe(MaintenanceStatus::Scheduled)
            ->and($updates[0]->message)->toBe('Planned reboot of the database hosts.');
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/maintenances/' . MaintenanceFixtures::ID));
    });

    it('reads an open-ended window', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => MaintenanceFixtures::payload(['scheduled_end' => null])])]);

        expect($client->maintenances()->get(MaintenanceFixtures::ID)->isOpenEnded())->toBeTrue();
    });

    it('raises not found', function (): void {
        [$client] = singleShotClient([MockResponse::error('The requested resource was not found.', 404)]);

        expect(fn(): Maintenance => $client->maintenances()->get('missing'))->toThrow(NotFoundException::class);
    });
});
