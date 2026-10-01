<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\EnrollmentKeyBuilder;
use LIVCK\Cloud\Data\CreatedEnrollmentKey;
use LIVCK\Cloud\Data\EnrollmentKey;
use LIVCK\Cloud\Data\EnrollmentKeyTag;
use LIVCK\Cloud\Data\Reference;
use LIVCK\Cloud\Enums\EnrollmentKeyStatus;
use LIVCK\Cloud\Enums\EnrollmentKeyType;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Query\EnrollmentKeyQuery;
use LIVCK\Cloud\Support\Secret;
use LIVCK\Cloud\Support\Uuid;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use LIVCK\Cloud\Tests\Fixtures\EnrollmentKeyFixtures;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;

describe('list', function (): void {
    it('lists keys as a page of hydrated DTOs', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([EnrollmentKeyFixtures::payload(), EnrollmentKeyFixtures::revokedFleet()])]);

        $page = $client->enrollmentKeys()->list();
        $key = $page->items[0];
        $tags = $key->tags ?? [];

        expect($page)->toBeInstanceOf(Page::class)
            ->and($page->total)->toBe(2)
            ->and($key)->toBeInstanceOf(EnrollmentKey::class)
            ->and($key->id)->toBe(EnrollmentKeyFixtures::ID)
            ->and($key->type)->toBe(EnrollmentKeyType::Single)
            ->and($key->name)->toBe('Customer 4711')
            ->and($key->tokenPrefix)->toBe(EnrollmentKeyFixtures::TOKEN_PREFIX)
            ->and($tags)->toHaveCount(1)
            ->and($tags[0])->toBeInstanceOf(EnrollmentKeyTag::class)
            ->and($tags[0]->id)->toBe(ServiceFixtures::TAG_ID)
            ->and($tags[0]->key)->toBe('kunde')
            ->and($tags[0]->value)->toBe('4711')
            ->and($tags[0]->color)->toBe('#6366f1')
            ->and($tags[0]->label)->toBe('kunde:4711')
            ->and($tags[0]->raw)->toBe(EnrollmentKeyFixtures::tag())
            ->and($key->tagIds())->toBe([ServiceFixtures::TAG_ID])
            ->and($key->hasTag('kunde:4711'))->toBeTrue()
            ->and($key->hasTag('kunde:4712'))->toBeFalse()
            ->and($key->agentTags)->toBeTrue()
            ->and($key->uses)->toBe(0)
            ->and($key->maxUses)->toBe(1)
            ->and($key->expiresAt->format(DATE_ATOM))->toBe('2026-10-02T12:00:00+00:00')
            ->and($key->revokedAt)->toBeNull()
            ->and($key->status)->toBe(EnrollmentKeyStatus::Active)
            ->and($key->isActive())->toBeTrue()
            ->and($key->services)->toBe([])
            ->and($key->latestService())->toBeNull()
            ->and($key->createdAt->format(DATE_ATOM))->toBe('2026-10-01T12:00:00+00:00')
            ->and($key->raw)->toBe(EnrollmentKeyFixtures::payload());

        $fleet = $page->items[1];
        $services = $fleet->services ?? [];

        expect($fleet->type)->toBe(EnrollmentKeyType::Fleet)
            ->and($fleet->status)->toBe(EnrollmentKeyStatus::Revoked)
            ->and($fleet->isActive())->toBeFalse()
            ->and($fleet->revokedAt?->format(DATE_ATOM))->toBe('2026-10-01T15:30:00+00:00')
            ->and($fleet->agentTags)->toBeFalse()
            ->and($fleet->uses)->toBe(2)
            ->and($fleet->maxUses)->toBe(200)
            ->and($fleet->tags)->toBe([])
            ->and($fleet->tagIds())->toBe([])
            ->and($services)->toHaveCount(2)
            ->and($services[0])->toBeInstanceOf(Reference::class)
            ->and($services[0]->name)->toBe('web-2')
            ->and($fleet->latestService()?->name)->toBe('web-2');

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/enrollment-keys') && $r->queryString() === '');
    });

    it('sends the status filter and paging of a query', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([])]);

        $client->enrollmentKeys()->list(EnrollmentKeyQuery::make()->withStatuses(EnrollmentKeyStatus::Exhausted, EnrollmentKeyStatus::Expired)->withPage(2)->withPerPage(10));

        expect($http->lastRequest()?->query())->toBe(['status' => ['exhausted', 'expired'], 'page' => '2', 'per_page' => '10'])
            ->and($http->lastRequest()?->queryString())->toBe('status%5B%5D=exhausted&status%5B%5D=expired&page=2&per_page=10');
    });

    it('keeps unknown enum values readable', function (): void {
        [$client] = fakeClient([MockResponse::page([EnrollmentKeyFixtures::payload(['type' => 'cluster', 'status' => 'suspended'])])]);

        $key = $client->enrollmentKeys()->list()->first();

        expect($key?->type)->toBe(EnrollmentKeyType::Unrecognized)
            ->and($key?->status)->toBe(EnrollmentKeyStatus::Unrecognized)
            ->and($key?->isActive())->toBeFalse()
            ->and($key?->raw['type'])->toBe('cluster')
            ->and($key?->raw['status'])->toBe('suspended');
    });

    it('reads a key that came without its tags and servers', function (): void {
        $payload = EnrollmentKeyFixtures::payload();
        unset($payload['tags'], $payload['services']);

        $key = EnrollmentKey::fromArray($payload);

        expect($key->tags)->toBeNull()
            ->and($key->services)->toBeNull()
            ->and($key->tagIds())->toBe([])
            ->and($key->hasTag('kunde:4711'))->toBeFalse()
            ->and($key->latestService())->toBeNull();
    });

    it('refuses to send an unrecognized status before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): Page => $client->enrollmentKeys()->list(EnrollmentKeyQuery::make()->withStatuses(EnrollmentKeyStatus::Unrecognized)))
            ->toThrow(InvalidArgumentException::class, 'Unrecognized');
        $http->assertNothingSent();
    });

    it('surfaces an unknown status the server refuses as a validation error', function (): void {
        [$client] = singleShotClient([MockResponse::error('The selected status.0 is invalid.', 422, ['status.0' => ['The selected status.0 is invalid.']])]);

        try {
            $client->enrollmentKeys()->list(EnrollmentKeyQuery::make()->withStatuses(EnrollmentKeyStatus::Active));
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->hasError('status.0'))->toBeTrue();
        }
    });
});

describe('each', function (): void {
    it('yields every key across all pages with the same filter', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::page([EnrollmentKeyFixtures::payload(['id' => 'a'])], currentPage: 1, lastPage: 2, perPage: 1),
            MockResponse::page([EnrollmentKeyFixtures::payload(['id' => 'b'])], currentPage: 2, lastPage: 2, perPage: 1),
        ]);

        $ids = array_map(
            static fn(EnrollmentKey $key): string => $key->id,
            iterator_to_array($client->enrollmentKeys()->each(EnrollmentKeyQuery::make()->withStatuses(EnrollmentKeyStatus::Active)->withPerPage(1)), false),
        );

        expect($ids)->toBe(['a', 'b'])
            ->and($http->recorded()[1]->query())->toBe(['status' => ['active'], 'page' => '2', 'per_page' => '1']);
    });
});

describe('get', function (): void {
    it('fetches a key with the servers enrolled with it, percent-encoded in the path', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => EnrollmentKeyFixtures::exhausted()])]);

        $key = $client->enrollmentKeys()->get(EnrollmentKeyFixtures::ID);

        expect($key->status)->toBe(EnrollmentKeyStatus::Exhausted)
            ->and($key->uses)->toBe(1)
            ->and($key->latestService()?->id)->toBe(ServiceFixtures::AGENT_ID)
            ->and($key->latestService()?->raw)->toBe(['id' => ServiceFixtures::AGENT_ID, 'name' => 'web-1'])
            ->and($key->raw)->not->toHaveKeys(['token', 'install_command']);
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/enrollment-keys/' . EnrollmentKeyFixtures::ID));

        [$client, $http] = fakeClient([MockResponse::json(['data' => EnrollmentKeyFixtures::payload()])]);
        $client->enrollmentKeys()->get('odd/id');

        expect($http->lastRequest()?->uri)->toBe('https://api.livck.cloud/v1/enrollment-keys/odd%2Fid');
    });

    it('raises not found for a key of another organization', function (): void {
        [$client] = singleShotClient([MockResponse::error('The requested resource was not found.', 404)]);

        expect(fn(): EnrollmentKey => $client->enrollmentKeys()->get('foreign'))->toThrow(NotFoundException::class);
    });
});

describe('create', function (): void {
    it('posts a single key with the server defaults and hands back the key once', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => EnrollmentKeyFixtures::created()], 201)]);

        $created = $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single());

        expect($created)->toBeInstanceOf(CreatedEnrollmentKey::class)
            ->and($created->key)->toBeInstanceOf(EnrollmentKey::class)
            ->and($created->key->id)->toBe(EnrollmentKeyFixtures::ID)
            ->and($created->key->status)->toBe(EnrollmentKeyStatus::Active)
            ->and($created->token)->toBeInstanceOf(Secret::class)
            ->and($created->token->reveal())->toBe(EnrollmentKeyFixtures::TOKEN)
            ->and(str_starts_with($created->token->reveal(), $created->key->tokenPrefix))->toBeTrue()
            ->and($created->installCommand)->toBeInstanceOf(Secret::class)
            ->and($created->installCommand->reveal())->toBe(EnrollmentKeyFixtures::INSTALL_COMMAND)
            ->and($created->installCommand->reveal())->toEndWith('--token ' . EnrollmentKeyFixtures::TOKEN)
            ->and($created->key->raw)->toBe(EnrollmentKeyFixtures::payload());

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/enrollment-keys')
            && $r->json() === ['type' => 'single']
            && $r->idempotencyKey() !== null);
    });

    it('sends name, tags by id and by name, expiry and the agent tag switch', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => EnrollmentKeyFixtures::created()], 201)]);

        $client->enrollmentKeys()->create(
            EnrollmentKeyBuilder::single('Customer 4711')
                ->tags(ServiceFixtures::TAG_ID, ' customer:4711 ', 'env=prod', 'customer:4711')
                ->expiresAt(new DateTimeImmutable('2026-10-03T14:00:00+02:00'))
                ->allowAgentTags(false),
        );

        expect($http->lastRequest()?->json())->toBe([
            'type' => 'single',
            'name' => 'Customer 4711',
            'tags' => [ServiceFixtures::TAG_ID, 'customer:4711', 'env=prod'],
            'expires_at' => '2026-10-03T12:00:00Z',
            'agent_tags' => false,
        ]);
    });

    it('posts a fleet key with its use cap', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => EnrollmentKeyFixtures::created(['type' => 'fleet', 'max_uses' => 200])], 201)]);

        $created = $client->enrollmentKeys()->create(EnrollmentKeyBuilder::fleet('Web servers', 200));

        expect($created->key->type)->toBe(EnrollmentKeyType::Fleet)
            ->and($created->key->maxUses)->toBe(200)
            ->and($http->lastRequest()?->json())->toBe(['type' => 'fleet', 'name' => 'Web servers', 'max_uses' => 200]);
    });

    it('keeps the key out of dumps, logs and JSON', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => EnrollmentKeyFixtures::created()], 201)]);

        $created = $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single());

        ob_start();
        var_dump($created);
        $dumped = (string) ob_get_clean();

        expect($dumped)->not->toContain(EnrollmentKeyFixtures::TOKEN)
            ->and($dumped)->toContain('[redacted]')
            ->and(print_r($created, true))->not->toContain(EnrollmentKeyFixtures::TOKEN)
            ->and(var_export($created, true))->not->toContain(EnrollmentKeyFixtures::TOKEN)
            ->and((string) json_encode($created))->not->toContain(EnrollmentKeyFixtures::TOKEN)
            ->and((string) json_encode($created->key->raw))->not->toContain(EnrollmentKeyFixtures::TOKEN)
            ->and(fn(): string => serialize($created))->toThrow(LogicException::class, 'must not be serialized');
    });

    it('redacts the key from any other field the response may carry', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => EnrollmentKeyFixtures::created([
            'install_command_windows' => 'iwr https://get.livck.cloud/install.ps1 -Token ' . EnrollmentKeyFixtures::TOKEN,
            'install' => ['cloud_init' => ['runcmd' => ['enroll --token ' . EnrollmentKeyFixtures::TOKEN]]],
        ])], 201)]);

        $created = $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single());

        expect($created->key->raw['install_command_windows'])->toBe('[redacted]')
            ->and($created->key->raw['install'])->toBe(['cloud_init' => ['runcmd' => ['[redacted]']]])
            ->and((string) json_encode($created->key->raw))->not->toContain(EnrollmentKeyFixtures::TOKEN);
    });

    it('fails loudly on a create response without the key', function (): void {
        $missing = EnrollmentKeyFixtures::created();
        unset($missing['token']);

        [$client] = fakeClient([
            MockResponse::json(['data' => $missing], 201),
            MockResponse::json(['data' => EnrollmentKeyFixtures::created(['token' => ''])], 201),
        ]);

        expect(fn(): CreatedEnrollmentKey => $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single()))
            ->toThrow(UnexpectedResponseException::class, 'token')
            ->and(fn(): CreatedEnrollmentKey => $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single()))
            ->toThrow(UnexpectedResponseException::class, 'without its key');
    });

    it('surfaces an unknown tag id as a validation error on its entry', function (): void {
        $message = 'No tag with the id "Zz0123456789abcdefghi" exists in this organization.';
        [$client] = singleShotClient([MockResponse::error($message, 422, ['tags.1' => [$message]])]);

        try {
            $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single()->tags('customer:4711', 'Zz0123456789abcdefghi'));
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->firstError('tags.1'))->toBe($message)
                ->and($e->hasError('tags.0'))->toBeFalse();
        }
    });

    it('surfaces switched-off server monitoring as permission denied', function (): void {
        [$client] = singleShotClient([MockResponse::error('Server monitoring is switched off for this organization.', 403)]);

        try {
            $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single());
            expect(false)->toBeTrue('a PermissionDeniedException was expected');
        } catch (PermissionDeniedException $e) {
            expect($e->status())->toBe(403)
                ->and($e->errorMessage())->toBe('Server monitoring is switched off for this organization.');
        }
    });

    it('refuses a blank tag before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): CreatedEnrollmentKey => $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single()->tags('customer:4711', '  ')))
            ->toThrow(InvalidArgumentException::class, 'blank');
        $http->assertNothingSent();
    });

    it('sends the given idempotency key instead of a generated one', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => EnrollmentKeyFixtures::created()], 201)]);

        $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single(), 'order-4711-server-1');

        expect($http->lastRequest()?->idempotencyKey())->toBe('order-4711-server-1');
    });

    it('generates a UUID v4 key when none is given', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => EnrollmentKeyFixtures::created()], 201)]);

        $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single());

        expect(Uuid::isV4((string) $http->lastRequest()?->idempotencyKey()))->toBeTrue();
    });

    it('refuses a malformed idempotency key before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): CreatedEnrollmentKey => $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single(), 'has space'))
            ->toThrow(InvalidArgumentException::class, 'visible ASCII');
        $http->assertNothingSent();
    });

    it('returns the same key when a retried create is replayed', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::networkError(),
            MockResponse::json(['data' => EnrollmentKeyFixtures::created()], 201)->replayed(),
        ]);

        $created = $client->enrollmentKeys()->create(EnrollmentKeyBuilder::single());

        expect($created->token->reveal())->toBe(EnrollmentKeyFixtures::TOKEN)
            ->and($http->recorded())->toHaveCount(2)
            ->and($http->recorded()[1]->idempotencyKey())->toBe($http->recorded()[0]->idempotencyKey());
    });
});

describe('revoke', function (): void {
    it('revokes with a DELETE, as often as it is asked', function (): void {
        [$client, $http] = fakeClient([MockResponse::noContent(), MockResponse::noContent()]);

        $client->enrollmentKeys()->revoke(EnrollmentKeyFixtures::ID);
        $client->enrollmentKeys()->revoke(EnrollmentKeyFixtures::ID);

        $http->assertSentCount(2);
        expect($http->recorded()[0]->matches('DELETE', '/v1/enrollment-keys/' . EnrollmentKeyFixtures::ID))->toBeTrue()
            ->and($http->recorded()[0]->body)->toBe('');
    });

    it('raises not found for an unknown key', function (): void {
        [$client] = singleShotClient([MockResponse::error('The requested resource was not found.', 404)]);

        expect(function () use ($client): void {
            $client->enrollmentKeys()->revoke('missing');
        })->toThrow(NotFoundException::class);
    });

    it('treats a 404 after a lost first attempt as revoked', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::networkError(),
            MockResponse::error('The requested resource was not found.', 404),
        ]);

        $client->enrollmentKeys()->revoke(EnrollmentKeyFixtures::ID);

        expect($http->recorded())->toHaveCount(2);
    });
});
