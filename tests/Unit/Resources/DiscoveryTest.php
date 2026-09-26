<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\Me;
use LIVCK\Cloud\Data\Probe;
use LIVCK\Cloud\Enums\TokenType;
use LIVCK\Cloud\Exceptions\AuthenticationException;
use LIVCK\Cloud\Exceptions\FeatureNotAvailableException;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use LIVCK\Cloud\Tests\Fixtures\CatalogFixture;
use LIVCK\Cloud\Tests\Fixtures\DiscoveryFixtures;

describe('me', function (): void {
    it('reads the calling token from the bare envelope', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(DiscoveryFixtures::me())]);

        $me = $client->me();

        expect($me)->toBeInstanceOf(Me::class)
            ->and($me->type)->toBe(TokenType::User)
            ->and($me->isManaged())->toBeFalse()
            ->and($me->permissions)->toBe(['services.view', 'incidents.view', 'maintenances.view'])
            ->and($me->can('services.view'))->toBeTrue()
            ->and($me->can('services.create'))->toBeFalse()
            ->and($me->organization->publicId)->toBe('nquKGB2qGm1X7pp60tUHZ')
            ->and($me->organization->name)->toBe('Example Hosting')
            ->and($me->rateLimit->requestsPerMinute)->toBe(120)
            ->and($me->service)->toBeNull()
            ->and($me->expiresAt)->toBeNull()
            ->and($me->expires())->toBeFalse()
            ->and($me->raw)->toBe(DiscoveryFixtures::me());

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/me'));
    });

    it('reads an agent token with its service and an expiring token', function (): void {
        [$client] = fakeClient([MockResponse::json(DiscoveryFixtures::me([
            'type' => 'managed',
            'permissions' => ['metrics.ingest'],
            'service' => ['public_id' => 'aGeNtSvC1234567890abc', 'name' => 'web-01'],
            'expires_at' => '2027-01-01T00:00:00+00:00',
        ]))]);

        $me = $client->me();

        expect($me->type)->toBe(TokenType::Managed)
            ->and($me->isManaged())->toBeTrue()
            ->and($me->service?->publicId)->toBe('aGeNtSvC1234567890abc')
            ->and($me->service?->name)->toBe('web-01')
            ->and($me->expiresAt?->format(DATE_ATOM))->toBe('2027-01-01T00:00:00+00:00')
            ->and($me->expires())->toBeTrue();
    });

    it('keeps an unknown token type readable', function (): void {
        [$client] = fakeClient([MockResponse::json(DiscoveryFixtures::me(['type' => 'service_account']))]);

        expect($client->me()->type)->toBe(TokenType::Unrecognized);
    });

    it('raises authentication errors', function (): void {
        [$client] = singleShotClient([MockResponse::error('Unauthenticated.', 401)]);

        expect(fn(): Me => $client->me())->toThrow(AuthenticationException::class);
    });
});

describe('probes', function (): void {
    it('lists the locations as a plain collection', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => DiscoveryFixtures::probes()])]);

        $probes = $client->probes();

        expect($probes)->toHaveCount(3)
            ->and($probes[0])->toBeInstanceOf(Probe::class)
            ->and($probes[0]->code)->toBe('ffm')
            ->and($probes[0]->name)->toBe('Frankfurt')
            ->and($probes[0]->location)->toBe('Germany')
            ->and($probes[0]->countryCode)->toBe('DE')
            ->and($probes[2]->location)->toBe('USA (New York)')
            ->and($probes[0]->raw)->toBe(DiscoveryFixtures::probes()[0]);

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/probes') && $r->queryString() === '');
    });

    it('surfaces a plan without API access as a feature gate', function (): void {
        [$client] = singleShotClient([MockResponse::error('Your plan does not include API access.', 403, extra: ['upsell' => ['reason' => 'feature', 'key' => 'api_access']])]);

        expect(fn(): array => $client->probes())->toThrow(FeatureNotAvailableException::class);
    });
});

describe('checkTypes', function (): void {
    it('reads the catalog keyed by check type', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(CatalogFixture::body())]);

        $catalog = $client->checkTypes();

        expect($catalog)->toBeInstanceOf(CheckTypeCatalog::class)
            ->and($catalog->keys())->toBe(['http', 'tcp', 'dns', 'icmp', 'ssl', 'manual'])
            ->and($catalog->type('http')->label)->toBe('HTTP/HTTPS')
            ->and($catalog->type('ssl')->interval?->min)->toBe(3600)
            ->and($catalog->raw)->toBe(CatalogFixture::data());

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/meta/check-types'));
    });

    it('rejects a catalog whose entries are not objects', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => ['http' => 'nope']])]);

        expect(fn(): CheckTypeCatalog => $client->checkTypes())->toThrow(UnexpectedResponseException::class, 'http');
    });
});
