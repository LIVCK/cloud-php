<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\Incident;
use LIVCK\Cloud\Data\Reference;
use LIVCK\Cloud\Enums\IncidentKind;
use LIVCK\Cloud\Enums\IncidentSeverity;
use LIVCK\Cloud\Enums\IncidentStatus;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Query\IncidentQuery;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use LIVCK\Cloud\Tests\Fixtures\IncidentFixtures;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;

describe('list', function (): void {
    it('lists incidents as a page of hydrated DTOs', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([IncidentFixtures::payload()])]);

        $page = $client->incidents()->list();
        $incident = $page->items[0];
        $services = $incident->services ?? [];

        expect($page)->toBeInstanceOf(Page::class)
            ->and($incident)->toBeInstanceOf(Incident::class)
            ->and($incident->id)->toBe(IncidentFixtures::ID)
            ->and($incident->title)->toBe('API health is experiencing problems')
            ->and($incident->titleTranslations)->toBe(['de' => 'API health hat derzeit Probleme', 'en' => 'API health is experiencing problems'])
            ->and($incident->status)->toBe(IncidentStatus::Resolved)
            ->and($incident->kind)->toBe(IncidentKind::Standard)
            ->and($incident->severity)->toBe(IncidentSeverity::Critical)
            ->and($incident->isPublished)->toBeTrue()
            ->and($incident->startedAt->format(DATE_ATOM))->toBe('2026-09-13T19:03:15+00:00')
            ->and($incident->resolvedAt?->format(DATE_ATOM))->toBe('2026-09-13T19:08:03+00:00')
            ->and($incident->createdAt->format(DATE_ATOM))->toBe('2026-09-13T19:03:15+00:00')
            ->and($incident->attachments)->toBe([])
            ->and($services)->toHaveCount(1)
            ->and($services[0])->toBeInstanceOf(Reference::class)
            ->and($services[0]->name)->toBe('API health')
            ->and($incident->serviceIds())->toBe([ServiceFixtures::ID])
            ->and($incident->updates)->toBeNull()
            ->and($incident->serviceImpact)->toBeNull()
            ->and($incident->isResolved())->toBeTrue()
            ->and($incident->isOpen())->toBeFalse()
            ->and($incident->isNotice())->toBeFalse()
            ->and($incident->raw)->toBe(IncidentFixtures::payload());

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/incidents') && $r->queryString() === '');
    });

    it('sends every filter as the server reads it', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([])]);

        $client->incidents()->list(IncidentQuery::make()
            ->withServiceIds([ServiceFixtures::ID, 'b' . str_repeat('1', 20)])
            ->withResolved()
            ->withPublished(false)
            ->withFrom(new DateTimeImmutable('2026-09-01T00:00:00Z'))
            ->withTo(new DateTimeImmutable('2026-10-01T02:00:00+02:00'))
            ->withKind(IncidentKind::Notice)
            ->withPage(3)
            ->withPerPage(20));

        expect($http->lastRequest()?->query())->toBe([
            'service_ids' => [ServiceFixtures::ID, 'b' . str_repeat('1', 20)],
            'resolved' => '1',
            'is_published' => '0',
            'from' => '2026-09-01T00:00:00Z',
            'to' => '2026-10-01T00:00:00Z',
            'kind' => 'notice',
            'page' => '3',
            'per_page' => '20',
        ]);
    });

    it('sends an explicitly empty service scope, which matches nothing', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([])]);

        $client->incidents()->list(IncidentQuery::make()->withServiceIds([]));

        expect($http->lastRequest()?->queryString())->toBe('service_ids=')
            ->and($http->lastRequest()?->query())->toBe(['service_ids' => '']);
    });

    it('sends no service scope at all for null', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([])]);

        $client->incidents()->list(IncidentQuery::make()->withServiceIds([ServiceFixtures::ID])->withServiceIds(null));

        expect($http->lastRequest()?->queryString())->toBe('');
    });

    it('keeps unknown enum values readable', function (): void {
        [$client] = fakeClient([MockResponse::page([IncidentFixtures::payload(['status' => 'escalated', 'kind' => 'drill', 'severity' => 'cosmic'])])]);

        $incident = $client->incidents()->list()->first();

        expect($incident?->status)->toBe(IncidentStatus::Unrecognized)
            ->and($incident?->kind)->toBe(IncidentKind::Unrecognized)
            ->and($incident?->severity)->toBe(IncidentSeverity::Unrecognized)
            ->and($incident?->isOpen())->toBeTrue()
            ->and($incident?->raw['severity'])->toBe('cosmic');
    });

    it('surfaces a malformed filter as a validation error', function (): void {
        [$client] = singleShotClient([MockResponse::error('The from field must be an ISO 8601 date or date-time.', 422, ['from' => ['The from field must be an ISO 8601 date or date-time.']])]);

        expect(fn(): Page => $client->incidents()->list())->toThrow(ValidationException::class);
    });
});

describe('each', function (): void {
    it('yields every incident across all pages, requesting the next page only when needed', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::page([IncidentFixtures::payload(['id' => 'a']), IncidentFixtures::payload(['id' => 'b'])], currentPage: 1, lastPage: 2, perPage: 2),
            MockResponse::page([IncidentFixtures::payload(['id' => 'c'])], currentPage: 2, lastPage: 2, perPage: 2),
        ]);

        $ids = [];

        foreach ($client->incidents()->each(IncidentQuery::make()->withResolved()) as $incident) {
            $ids[] = $incident->id;

            if ($incident->id === 'b') {
                expect($http->recorded())->toHaveCount(1);
            }
        }

        expect($ids)->toBe(['a', 'b', 'c'])
            ->and($http->recorded()[1]->query())->toBe(['resolved' => '1', 'page' => '2']);
    });
});

describe('get', function (): void {
    it('fetches one incident with its timeline and attachments', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => IncidentFixtures::detailed()])]);

        $incident = $client->incidents()->get(IncidentFixtures::ID);
        $updates = $incident->updates ?? [];

        expect($updates)->toHaveCount(2)
            ->and($updates[0]->id)->toBe('Agx9NgNUZKbxwn8YX1uK2')
            ->and($updates[0]->status)->toBe(IncidentStatus::Investigating)
            ->and($updates[0]->message)->toBe('Detected automatically: API health is down.')
            ->and($updates[0]->notifySubscribers)->toBeTrue()
            ->and($updates[0]->createdAt->format(DATE_ATOM))->toBe('2026-09-13T19:03:15+00:00')
            ->and($updates[1]->status)->toBe(IncidentStatus::Resolved)
            ->and($incident->attachments)->toHaveCount(1)
            ->and($incident->attachments[0]->name)->toBe('postmortem.pdf')
            ->and($incident->attachments[0]->size)->toBe(48211)
            ->and($incident->attachments[0]->url)->toBe('https://files.livck.cloud/attachments/postmortem.pdf');
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/incidents/' . IncidentFixtures::ID));
    });

    it('raises not found', function (): void {
        [$client] = singleShotClient([MockResponse::error('The requested resource was not found.', 404)]);

        expect(fn(): Incident => $client->incidents()->get('missing'))->toThrow(NotFoundException::class);
    });
});
