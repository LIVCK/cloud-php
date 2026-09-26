<?php

declare(strict_types=1);

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\Data\CustomDomain;
use LIVCK\Cloud\Data\DnsRecord;
use LIVCK\Cloud\Enums\CustomDomainErrorCode;
use LIVCK\Cloud\Enums\CustomDomainStatus;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PlanLimitException;
use LIVCK\Cloud\Exceptions\RateLimitException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Support\Uuid;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use LIVCK\Cloud\Tests\Fixtures\StatuspageFixtures;

describe('all', function (): void {
    it('lists the domains of the page, not paginated', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => [
            StatuspageFixtures::activeDomain(['id' => 'a', 'hostname' => 'a.example.com']),
            StatuspageFixtures::customDomain(['id' => 'b', 'hostname' => 'b.example.com']),
        ]])]);

        $domains = $client->statuspages()->customDomains(StatuspageFixtures::PAGE_ID)->all();

        expect($domains)->toHaveCount(2)
            ->and($domains[0])->toBeInstanceOf(CustomDomain::class)
            ->and($domains[0]->isActive())->toBeTrue()
            ->and($domains[1]->hostname)->toBe('b.example.com');

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', StatuspageFixtures::DOMAINS_PATH) && $r->queryString() === '');
    });

    it('returns an empty list for a page without domains', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => []])]);

        expect($client->statuspages()->customDomains('p')->all())->toBe([]);
    });
});

describe('get', function (): void {
    it('hydrates every field of an active domain', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::activeDomain()])]);

        $domain = $client->statuspages()->customDomains(StatuspageFixtures::PAGE_ID)->get(StatuspageFixtures::DOMAIN_ID);

        expect($domain->id)->toBe(StatuspageFixtures::DOMAIN_ID)
            ->and($domain->hostname)->toBe('status.example.com')
            ->and($domain->status)->toBe(CustomDomainStatus::Active)
            ->and($domain->isActive())->toBeTrue()
            ->and($domain->cnameTarget)->toBe('edge.livck-status.com')
            ->and($domain->txtRecordName)->toBe('_livck-verify.status.example.com')
            ->and($domain->txtRecordValue)->toBe(StatuspageFixtures::TXT_TOKEN)
            ->and($domain->verified)->toBeTrue()
            ->and($domain->verifiedAt?->format(DATE_ATOM))->toBe('2026-09-26T05:10:28+00:00')
            ->and($domain->lastErrorCode)->toBeNull()
            ->and($domain->statuspageId)->toBe(StatuspageFixtures::PAGE_ID)
            ->and($domain->raw)->toBe(StatuspageFixtures::activeDomain());

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', StatuspageFixtures::DOMAINS_PATH . '/' . StatuspageFixtures::DOMAIN_ID));
    });

    it('reads a failed domain with the reason the server gave up', function (string $code, CustomDomainErrorCode $expected): void {
        [$client] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::customDomain([
            'status' => 'failed',
            'last_error_code' => $code,
            'statuspage_id' => null,
        ])])]);

        $domain = $client->statuspages()->customDomains('p')->get('d');

        expect($domain->status)->toBe(CustomDomainStatus::Failed)
            ->and($domain->isActive())->toBeFalse()
            ->and($domain->lastErrorCode)->toBe($expected)
            ->and($domain->statuspageId)->toBeNull();
    })->with([
        ['verification_timeout', CustomDomainErrorCode::VerificationTimeout],
        ['stale_timeout', CustomDomainErrorCode::StaleTimeout],
    ]);

    it('keeps unknown statuses and error codes readable', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::customDomain([
            'status' => 'pending_provision',
            'last_error_code' => 'caa_forbids_issuance',
        ])])]);

        $domain = $client->statuspages()->customDomains('p')->get('d');

        expect($domain->status)->toBe(CustomDomainStatus::Unrecognized)
            ->and($domain->lastErrorCode)->toBe(CustomDomainErrorCode::Unrecognized)
            ->and($domain->raw['status'])->toBe('pending_provision')
            ->and($domain->raw['last_error_code'])->toBe('caa_forbids_issuance');
    });

    it('raises not found for a detached domain', function (): void {
        [$client] = singleShotClient([MockResponse::error('The requested resource was not found.', 404)]);

        expect(fn(): CustomDomain => $client->statuspages()->customDomains('p')->get('detached'))->toThrow(NotFoundException::class);
    });
});

describe('attach', function (): void {
    it('attaches a hostname and returns the records to publish', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::customDomain()], 201)]);

        $domain = $client->statuspages()->customDomains(StatuspageFixtures::PAGE_ID)->attach('Status.Example.com');

        expect($domain->status)->toBe(CustomDomainStatus::PendingVerification)
            ->and($domain->verified)->toBeFalse()
            ->and($domain->verifiedAt)->toBeNull()
            ->and($domain->lastErrorCode)->toBeNull()
            ->and($domain->dnsRecords())->toEqual([
                new DnsRecord(DnsRecord::CNAME, 'status.example.com', 'edge.livck-status.com'),
                new DnsRecord(DnsRecord::TXT, '_livck-verify.status.example.com', StatuspageFixtures::TXT_TOKEN),
            ]);

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', StatuspageFixtures::DOMAINS_PATH)
            && $r->json() === ['hostname' => 'Status.Example.com']
            && $r->idempotencyKey() !== null);
    });

    it('surfaces the domain limit of the plan', function (): void {
        [$client] = singleShotClient([MockResponse::error(
            'The custom domain limit for this status page has been reached.',
            403,
            extra: ['limit' => 1, 'usage' => 1, 'upsell' => ['reason' => 'limit', 'key' => 'custom_domains']],
        )]);

        try {
            $client->statuspages()->customDomains('p')->attach('two.example.com');
            expect(false)->toBeTrue('a PlanLimitException was expected');
        } catch (PlanLimitException $e) {
            expect($e->limitKey())->toBe('custom_domains')
                ->and($e->limit())->toBe(1)
                ->and($e->usage())->toBe(1)
                ->and($e->status())->toBe(403);
        }
    });

    it('surfaces a hostname in use elsewhere as a validation error', function (): void {
        $message = "The hostname 'taken.example.com' is already in use.";
        [$client] = singleShotClient([MockResponse::error($message, 422, ['hostname' => [$message]])]);

        try {
            $client->statuspages()->customDomains('p')->attach('taken.example.com');
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->firstError('hostname'))->toBe($message);
        }
    });

    it('refuses a blank hostname before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): CustomDomain => $client->statuspages()->customDomains('p')->attach(' '))->toThrow(InvalidArgumentException::class, 'hostname must not be blank');
        $http->assertNothingSent();
    });

    it('sends the given idempotency key instead of a generated one', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::customDomain()], 201)]);

        $client->statuspages()->customDomains('p')->attach('status.example.com', 'onboard-4711-domain');

        expect($http->lastRequest()?->idempotencyKey())->toBe('onboard-4711-domain');
    });

    it('generates a UUID v4 key when none is given', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::customDomain()], 201)]);

        $client->statuspages()->customDomains('p')->attach('status.example.com');

        expect(Uuid::isV4((string) $http->lastRequest()?->idempotencyKey()))->toBeTrue();
    });

    it('refuses a malformed idempotency key before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): CustomDomain => $client->statuspages()->customDomains('p')->attach('status.example.com', 'has space'))
            ->toThrow(InvalidArgumentException::class, 'visible ASCII');
        $http->assertNothingSent();
    });
});

describe('verify', function (): void {
    it('checks right away with an empty POST', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::activeDomain()])]);

        $domain = $client->statuspages()->customDomains(StatuspageFixtures::PAGE_ID)->verify(StatuspageFixtures::DOMAIN_ID);

        expect($domain->isActive())->toBeTrue();
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', StatuspageFixtures::DOMAINS_PATH . '/' . StatuspageFixtures::DOMAIN_ID . '/verify')
            && $r->body === '');
    });

    it('names what is still missing', function (string $code, CustomDomainErrorCode $expected): void {
        [$client] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::customDomain(['last_error_code' => $code])])]);

        $domain = $client->statuspages()->customDomains('p')->verify('d');

        expect($domain->status)->toBe(CustomDomainStatus::PendingVerification)
            ->and($domain->lastErrorCode)->toBe($expected);
    })->with([
        ['no_records', CustomDomainErrorCode::NoRecords],
        ['wrong_target', CustomDomainErrorCode::WrongTarget],
        ['ownership_missing', CustomDomainErrorCode::OwnershipMissing],
        ['dnssec', CustomDomainErrorCode::Dnssec],
    ]);

    it('waits out the per-domain cooldown and checks again with the same key', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::error('Verification was checked moments ago. Try again shortly.', 429)->withRetryAfter(7),
            MockResponse::json(['data' => StatuspageFixtures::activeDomain()]),
        ]);

        $domain = $client->statuspages()->customDomains('p')->verify('d');

        $recorded = $http->recorded();

        expect($domain->isActive())->toBeTrue()
            ->and($http->delays())->toBe([7.0])
            ->and($recorded)->toHaveCount(2)
            ->and($recorded[1]->idempotencyKey())->toBe($recorded[0]->idempotencyKey());
    });

    it('gives up on a cooldown longer than the client waits', function (): void {
        [$client, $http] = fakeClient(
            [MockResponse::error('Verification was checked moments ago. Try again shortly.', 429)->withRetryAfter(9)],
            new ClientOptions(maxRetryAfter: 5),
        );

        try {
            $client->statuspages()->customDomains('p')->verify('d');
            expect(false)->toBeTrue('a RateLimitException was expected');
        } catch (RateLimitException $e) {
            expect($e->retryAfter())->toBe(9)
                ->and($http->recorded())->toHaveCount(1);
        }
    });

    it('refuses a domain that is not pending without field errors', function (): void {
        [$client] = singleShotClient([MockResponse::error('This domain is not pending verification.', 422)]);

        try {
            $client->statuspages()->customDomains('p')->verify('d');
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->hasFieldErrors())->toBeFalse()
                ->and($e->errorMessage())->toBe('This domain is not pending verification.');
        }
    });
});

describe('detach', function (): void {
    it('detaches and returns nothing', function (): void {
        [$client, $http] = fakeClient([MockResponse::noContent()]);

        $client->statuspages()->customDomains(StatuspageFixtures::PAGE_ID)->detach(StatuspageFixtures::DOMAIN_ID);

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('DELETE', StatuspageFixtures::DOMAINS_PATH . '/' . StatuspageFixtures::DOMAIN_ID) && $r->body === '');

        expect($http->recorded())->toHaveCount(1);
    });

    it('counts a repeated detach that finds the domain gone as done', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::networkError(),
            MockResponse::error('The requested resource was not found.', 404),
        ]);

        $client->statuspages()->customDomains('p')->detach('d');

        expect($http->recorded())->toHaveCount(2);
    });
});
