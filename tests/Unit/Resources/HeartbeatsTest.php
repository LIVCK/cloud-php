<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\HeartbeatBuilder;
use LIVCK\Cloud\Builders\HeartbeatDeclaration;
use LIVCK\Cloud\Builders\HeartbeatSchedule;
use LIVCK\Cloud\Data\DeclaredHeartbeat;
use LIVCK\Cloud\Data\DeclaredSchedule;
use LIVCK\Cloud\Data\HeartbeatDeclarationCounts;
use LIVCK\Cloud\Data\HeartbeatDeclarationResult;
use LIVCK\Cloud\Data\NotCreatedHeartbeats;
use LIVCK\Cloud\Data\UnlistedHeartbeat;
use LIVCK\Cloud\Enums\HeartbeatDeclarationOutcome;
use LIVCK\Cloud\Enums\HeartbeatKind;
use LIVCK\Cloud\Enums\HeartbeatNotCreatedReason;
use LIVCK\Cloud\Enums\HeartbeatScheduleType;
use LIVCK\Cloud\Enums\ServiceStatus;
use LIVCK\Cloud\Exceptions\ConflictException;
use LIVCK\Cloud\Exceptions\FeatureNotAvailableException;
use LIVCK\Cloud\Exceptions\HeartbeatDeclarationConflictException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\RestrictedViewException;
use LIVCK\Cloud\Exceptions\ServerException;
use LIVCK\Cloud\Exceptions\TransportException;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Support\Secret;
use LIVCK\Cloud\Support\Uuid;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use LIVCK\Cloud\Tests\Fixtures\HeartbeatFixtures;

function shopDeclaration(): HeartbeatDeclaration
{
    return HeartbeatDeclaration::of(
        'shop',
        'production',
        HeartbeatBuilder::schedule('export:nightly', HeartbeatSchedule::cron('0 3 * * *', 'Europe/Berlin')),
        HeartbeatBuilder::queue('queue:default', HeartbeatSchedule::every(60))->name('Default queue')->grace(120)->maxDuration(900),
        HeartbeatBuilder::job('App/Jobs/ImportCustomers', HeartbeatSchedule::every(86400)),
    );
}

/**
 * @param array<string, mixed>|null $data
 */
function declared(?array $data = null): MockResponse
{
    return MockResponse::json(['data' => $data ?? HeartbeatFixtures::result()]);
}

describe('declare', function (): void {
    it('posts the declaration as built', function (): void {
        [$client, $http] = fakeClient([declared()]);

        $client->heartbeats()->declare(shopDeclaration());

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/heartbeats/declare')
            && $r->json() === [
                'app' => 'shop',
                'environment' => 'production',
                'heartbeats' => [
                    ['key' => 'export:nightly', 'kind' => 'schedule', 'schedule' => ['type' => 'cron', 'expression' => '0 3 * * *', 'timezone' => 'Europe/Berlin']],
                    ['key' => 'queue:default', 'kind' => 'queue', 'schedule' => ['type' => 'simple', 'period_seconds' => 60], 'name' => 'Default queue', 'grace_seconds' => 120, 'max_duration_seconds' => 900],
                    ['key' => 'App/Jobs/ImportCustomers', 'kind' => 'job', 'schedule' => ['type' => 'simple', 'period_seconds' => 86400]],
                ],
            ]);
    });

    it('sends an explicit null to remove the run time limit', function (): void {
        [$client, $http] = fakeClient([declared()]);

        $client->heartbeats()->declare(HeartbeatDeclaration::of('shop', 'production', HeartbeatBuilder::queue('queue:default', HeartbeatSchedule::every(60))->noMaxDuration()));

        expect($http->lastRequest()?->body)->toContain('"max_duration_seconds":null');
    });

    it('hydrates every heartbeat with the values in effect', function (): void {
        [$client] = fakeClient([declared()]);

        $result = $client->heartbeats()->declare(shopDeclaration());
        $nightly = $result->heartbeats[0];
        $queue = $result->heartbeats[1];

        expect($result)->toBeInstanceOf(HeartbeatDeclarationResult::class)
            ->and($result->heartbeats)->toHaveCount(2)
            ->and($nightly)->toBeInstanceOf(DeclaredHeartbeat::class)
            ->and($nightly->serviceId)->toBe(HeartbeatFixtures::NIGHTLY_ID)
            ->and($nightly->key)->toBe('export:nightly')
            ->and($nightly->name)->toBe('export:nightly')
            ->and($nightly->kind)->toBe(HeartbeatKind::Schedule)
            ->and($nightly->schedule)->toBeInstanceOf(DeclaredSchedule::class)
            ->and($nightly->schedule->type)->toBe(HeartbeatScheduleType::Cron)
            ->and($nightly->schedule->isCron())->toBeTrue()
            ->and($nightly->schedule->periodSeconds)->toBeNull()
            ->and($nightly->schedule->expression)->toBe('0 3 * * *')
            ->and($nightly->schedule->timezone)->toBe('Europe/Berlin')
            ->and($nightly->schedule->raw)->toBe(HeartbeatFixtures::nightly()['schedule'])
            ->and($nightly->graceSeconds)->toBe(60)
            ->and($nightly->scheduleTolerance)->toBe(1)
            ->and($nightly->maxDurationSeconds)->toBeNull()
            ->and($nightly->pingUrl)->toBeInstanceOf(Secret::class)
            ->and($nightly->pingUrl?->reveal())->toBe(HeartbeatFixtures::NIGHTLY_URL)
            ->and($nightly->status)->toBe(ServiceStatus::Unknown)
            ->and($nightly->paused)->toBeFalse()
            ->and($nightly->result)->toBe(HeartbeatDeclarationOutcome::Unchanged)
            ->and($nightly->wasCreated())->toBeFalse()
            ->and($nightly->wasUpdated())->toBeFalse()
            ->and($queue->kind)->toBe(HeartbeatKind::Queue)
            ->and($queue->name)->toBe('Default queue')
            ->and($queue->schedule->type)->toBe(HeartbeatScheduleType::Simple)
            ->and($queue->schedule->isCron())->toBeFalse()
            ->and($queue->schedule->periodSeconds)->toBe(60)
            ->and($queue->schedule->expression)->toBeNull()
            ->and($queue->schedule->timezone)->toBe('UTC')
            ->and($queue->graceSeconds)->toBe(120)
            ->and($queue->scheduleTolerance)->toBe(2)
            ->and($queue->maxDurationSeconds)->toBe(900)
            ->and($queue->status)->toBe(ServiceStatus::Up)
            ->and($queue->result)->toBe(HeartbeatDeclarationOutcome::Updated)
            ->and($queue->wasUpdated())->toBeTrue();
    });

    it('reports what was not created as part of a successful answer', function (): void {
        [$client] = fakeClient([declared()]);

        $result = $client->heartbeats()->declare(shopDeclaration());

        expect($result->notCreated)->toBeInstanceOf(NotCreatedHeartbeats::class)
            ->and($result->notCreated->keys)->toBe(['App/Jobs/ImportCustomers'])
            ->and($result->notCreated->reason)->toBe(HeartbeatNotCreatedReason::ServiceLimit)
            ->and($result->notCreated->missing)->toBe(1)
            ->and($result->notCreated->retryAfter)->toBeNull()
            ->and($result->notCreated->isEmpty())->toBeFalse()
            ->and($result->notCreated->raw)->toBe(HeartbeatFixtures::notCreated())
            ->and($result->allExist())->toBeFalse()
            ->and($result->heartbeat('App/Jobs/ImportCustomers'))->toBeNull()
            ->and($result->counts)->toBeInstanceOf(HeartbeatDeclarationCounts::class)
            ->and($result->counts->created)->toBe(0)
            ->and($result->counts->updated)->toBe(1)
            ->and($result->counts->unchanged)->toBe(1)
            ->and($result->counts->changedAnything())->toBeTrue()
            ->and($result->counts->raw)->toBe(['created' => 0, 'updated' => 1, 'unchanged' => 1]);
    });

    it('reads the creation limit with its wait', function (): void {
        [$client] = fakeClient([declared(HeartbeatFixtures::result([
            'not_created' => ['keys' => ['App/Jobs/ImportCustomers'], 'reason' => 'creation_rate', 'missing' => null, 'retry_after' => 1800],
        ]))]);

        $notCreated = $client->heartbeats()->declare(shopDeclaration())->notCreated;

        expect($notCreated->reason)->toBe(HeartbeatNotCreatedReason::CreationRate)
            ->and($notCreated->missing)->toBeNull()
            ->and($notCreated->retryAfter)->toBe(1800);
    });

    it('names the heartbeats the declaration left out, without touching them', function (): void {
        [$client] = fakeClient([declared()]);

        $result = $client->heartbeats()->declare(shopDeclaration());

        expect($result->unlisted)->toHaveCount(1)
            ->and($result->unlisted[0])->toBeInstanceOf(UnlistedHeartbeat::class)
            ->and($result->unlisted[0]->key)->toBe('report:weekly')
            ->and($result->unlisted[0]->serviceId)->toBe(HeartbeatFixtures::UNLISTED_ID)
            ->and($result->unlisted[0]->raw)->toBe(['key' => 'report:weekly', 'service_id' => HeartbeatFixtures::UNLISTED_ID])
            ->and($result->unlistedKeys())->toBe(['report:weekly'])
            ->and($result->skippedTags)->toBe([]);
    });

    it('reads a complete declaration', function (): void {
        [$client] = fakeClient([declared(HeartbeatFixtures::complete())]);

        $result = $client->heartbeats()->declare(shopDeclaration());

        expect($result->allExist())->toBeTrue()
            ->and($result->notCreated->isEmpty())->toBeTrue()
            ->and($result->notCreated->reason)->toBeNull()
            ->and($result->unlisted)->toBe([])
            ->and($result->skippedTags)->toBe(['team:payments'])
            ->and($result->heartbeat('queue:default')?->wasCreated())->toBeTrue()
            ->and($result->counts->created)->toBe(1);
    });

    it('reads an empty declaration', function (): void {
        [$client] = fakeClient([declared([
            'heartbeats' => [],
            'not_created' => HeartbeatFixtures::nothingMissing(),
            'unlisted' => [['key' => 'report:weekly', 'service_id' => HeartbeatFixtures::UNLISTED_ID]],
            'skipped_tags' => [],
            'counts' => ['created' => 0, 'updated' => 0, 'unchanged' => 0],
        ])]);

        $result = $client->heartbeats()->declare(HeartbeatDeclaration::of('shop', 'production'));

        expect($result->heartbeats)->toBe([])
            ->and($result->pingUrls())->toBe([])
            ->and($result->allExist())->toBeTrue()
            ->and($result->counts->changedAnything())->toBeFalse()
            ->and($result->unlistedKeys())->toBe(['report:weekly']);
    });

    it('finds a heartbeat by its exact key', function (): void {
        [$client] = fakeClient([declared()]);

        $result = $client->heartbeats()->declare(shopDeclaration());

        expect($result->heartbeat('queue:default')?->serviceId)->toBe(HeartbeatFixtures::QUEUE_ID)
            ->and($result->heartbeat('Queue:Default'))->toBeNull()
            ->and($result->heartbeat('report:weekly'))->toBeNull();
    });

    it('maps each key to its ping URL', function (): void {
        [$client] = fakeClient([declared()]);

        $urls = $client->heartbeats()->declare(shopDeclaration())->pingUrls();

        expect(array_keys($urls))->toBe(['export:nightly', 'queue:default'])
            ->and($urls['export:nightly']->reveal())->toBe(HeartbeatFixtures::NIGHTLY_URL)
            ->and($urls['queue:default']->reveal())->toBe(HeartbeatFixtures::QUEUE_URL);
    });

    it('reads a heartbeat whose ping URL LIVCK cannot read any more', function (): void {
        [$client] = fakeClient([declared(HeartbeatFixtures::result([
            'heartbeats' => [HeartbeatFixtures::nightly(['ping_url' => null]), HeartbeatFixtures::queue()],
        ]))]);

        $result = $client->heartbeats()->declare(shopDeclaration());

        expect($result->heartbeats[0]->pingUrl)->toBeNull()
            ->and(array_keys($result->pingUrls()))->toBe(['queue:default']);
    });

    it('keeps unknown enum values readable', function (): void {
        [$client] = fakeClient([declared(HeartbeatFixtures::result([
            'heartbeats' => [HeartbeatFixtures::nightly([
                'kind' => 'cronjob',
                'schedule' => ['type' => 'rrule', 'period_seconds' => null, 'expression' => 'FREQ=DAILY', 'timezone' => 'UTC'],
                'status' => 'sleeping',
                'result' => 'adopted',
            ])],
            'not_created' => ['keys' => ['x'], 'reason' => 'quota', 'missing' => null, 'retry_after' => null],
        ]))]);

        $result = $client->heartbeats()->declare(shopDeclaration());
        $heartbeat = $result->heartbeats[0];

        expect($heartbeat->kind)->toBe(HeartbeatKind::Unrecognized)
            ->and($heartbeat->raw['kind'])->toBe('cronjob')
            ->and($heartbeat->schedule->type)->toBe(HeartbeatScheduleType::Unrecognized)
            ->and($heartbeat->schedule->raw['type'])->toBe('rrule')
            ->and($heartbeat->status)->toBe(ServiceStatus::Unrecognized)
            ->and($heartbeat->result)->toBe(HeartbeatDeclarationOutcome::Unrecognized)
            ->and($heartbeat->raw['result'])->toBe('adopted')
            ->and($result->notCreated->reason)->toBe(HeartbeatNotCreatedReason::Unrecognized)
            ->and($result->notCreated->raw['reason'])->toBe('quota');
    });

    it('keeps the ping URLs out of dumps, JSON and the raw payloads', function (): void {
        [$client] = fakeClient([declared(HeartbeatFixtures::result([
            'heartbeats' => [HeartbeatFixtures::nightly(['ping_hint' => 'curl ' . HeartbeatFixtures::NIGHTLY_URL . '/start', 'ping' => ['token' => HeartbeatFixtures::NIGHTLY_TOKEN]]), HeartbeatFixtures::queue()],
        ]))]);

        $result = $client->heartbeats()->declare(shopDeclaration());
        $nightly = $result->heartbeats[0];

        ob_start();
        var_dump($result);
        $dumped = (string) ob_get_clean();

        foreach ([HeartbeatFixtures::NIGHTLY_TOKEN, HeartbeatFixtures::QUEUE_TOKEN] as $token) {
            expect($dumped)->not->toContain($token)
                ->and(print_r($result, true))->not->toContain($token)
                ->and(var_export($result, true))->not->toContain($token)
                ->and((string) json_encode($result))->not->toContain($token)
                ->and((string) json_encode($result->raw))->not->toContain($token);
        }

        expect($nightly->raw)->not->toHaveKey('ping_url')
            ->and($nightly->raw['ping_hint'])->toBe('[redacted]')
            ->and($nightly->raw['ping'])->toBe(['token' => '[redacted]'])
            ->and($nightly->raw['key'])->toBe('export:nightly')
            ->and($result->raw['heartbeats'])->toBe([$nightly->raw, $result->heartbeats[1]->raw])
            ->and($result->heartbeats[1]->raw)->not->toHaveKey('ping_url')
            ->and($result->raw['unlisted'])->toBe(HeartbeatFixtures::result()['unlisted'])
            ->and(fn(): string => serialize($result))->toThrow(LogicException::class, 'must not be serialized');
    });

    it('fails loudly on a heartbeat without its ping URL field or with an empty one', function (): void {
        $missing = HeartbeatFixtures::nightly();
        unset($missing['ping_url']);

        [$client] = fakeClient([
            declared(HeartbeatFixtures::result(['heartbeats' => [$missing]])),
            declared(HeartbeatFixtures::result(['heartbeats' => [HeartbeatFixtures::nightly(['ping_url' => ''])]])),
            declared(HeartbeatFixtures::result(['heartbeats' => [HeartbeatFixtures::nightly(['grace_seconds' => '60'])]])),
        ]);

        expect(fn(): HeartbeatDeclarationResult => $client->heartbeats()->declare(shopDeclaration()))
            ->toThrow(UnexpectedResponseException::class, 'ping_url')
            ->and(fn(): HeartbeatDeclarationResult => $client->heartbeats()->declare(shopDeclaration()))
            ->toThrow(UnexpectedResponseException::class, 'empty ping URL')
            ->and(fn(): HeartbeatDeclarationResult => $client->heartbeats()->declare(shopDeclaration()))
            ->toThrow(UnexpectedResponseException::class, 'grace_seconds');
    });

    it('fails loudly on an answer without its parts', function (string $field): void {
        $data = HeartbeatFixtures::result();
        unset($data[$field]);

        [$client] = fakeClient([declared($data)]);

        expect(fn(): HeartbeatDeclarationResult => $client->heartbeats()->declare(shopDeclaration()))
            ->toThrow(UnexpectedResponseException::class, $field);
    })->with(['not_created', 'counts']);

    it('sends the given idempotency key instead of a generated one', function (): void {
        [$client, $http] = fakeClient([declared()]);

        $client->heartbeats()->declare(shopDeclaration(), 'deploy-4711');

        expect($http->lastRequest()?->idempotencyKey())->toBe('deploy-4711');
    });

    it('generates a UUID v4 key when none is given', function (): void {
        [$client, $http] = fakeClient([declared()]);

        $client->heartbeats()->declare(shopDeclaration());

        expect(Uuid::isV4((string) $http->lastRequest()?->idempotencyKey()))->toBeTrue();
    });

    it('refuses a malformed idempotency key before anything is sent', function (): void {
        [$client, $http] = fakeClient([declared()]);

        expect(fn(): HeartbeatDeclarationResult => $client->heartbeats()->declare(shopDeclaration(), 'has spaces'))
            ->toThrow(InvalidArgumentException::class, 'Idempotency-Key');

        $http->assertNothingSent();
    });

    it('repeats the declaration with the same key after a lost connection', function (): void {
        [$client, $http] = fakeClient([MockResponse::networkError(), declared()]);

        $client->heartbeats()->declare(shopDeclaration());

        $keys = array_map(static fn(RecordedRequest $r): ?string => $r->idempotencyKey(), $http->recorded());

        expect($keys)->toHaveCount(2)
            ->and($keys[0])->not->toBeNull()
            ->and($keys[1])->toBe($keys[0]);
    });
});

describe('declare errors', function (): void {
    it('raises a retryable conflict when another declaration ran at the same time', function (): void {
        $message = 'Another declaration of the same heartbeats is running. Send the declaration again.';
        [$client, $http] = fakeClient([MockResponse::error($message, 409), declared()]);

        try {
            $client->heartbeats()->declare(shopDeclaration());
            expect(false)->toBeTrue('a HeartbeatDeclarationConflictException was expected');
        } catch (HeartbeatDeclarationConflictException $e) {
            expect($e)->toBeInstanceOf(ConflictException::class)
                ->and($e->isRetryable())->toBeTrue()
                ->and($e->isIdempotencyKeyInProgress())->toBeFalse()
                ->and($e->requiresConfirmation())->toBeFalse()
                ->and($e->status())->toBe(409)
                ->and($e->errorMessage())->toBe($message)
                ->and($e->requestMethod())->toBe('POST');
        }

        // The SDK hands the collision back at once: the server already retried it.
        $http->assertSentCount(1);
        expect($http->delays())->toBe([]);
    });

    it('leaves an idempotency key still in progress to the transport', function (): void {
        $busy = MockResponse::error('A request with this Idempotency-Key is still being processed.', 409)->withRetryAfter(1);
        [$client, $http] = fakeClient([$busy, declared()]);

        $result = $client->heartbeats()->declare(shopDeclaration());

        expect($result->heartbeats)->toHaveCount(2);
        $http->assertSentCount(2);
    });

    it('raises the plain conflict once a key in progress outlasts the retries', function (): void {
        $busy = MockResponse::error('A request with this Idempotency-Key is still being processed.', 409)->withRetryAfter(1);
        [$client] = singleShotClient([$busy]);

        try {
            $client->heartbeats()->declare(shopDeclaration());
            expect(false)->toBeTrue('a ConflictException was expected');
        } catch (ConflictException $e) {
            expect($e)->not->toBeInstanceOf(HeartbeatDeclarationConflictException::class)
                ->and($e->isIdempotencyKeyInProgress())->toBeTrue()
                ->and($e->isRetryable())->toBeTrue();
        }
    });

    it('tells the plan without API access, a limited view and a missing ability apart', function (): void {
        [$client] = singleShotClient([
            MockResponse::error('API access is available on the Team plan and above.', 403, extra: ['upsell' => ['reason' => 'feature', 'key' => 'api_access']]),
            MockResponse::error('Declaring heartbeats requires a token that sees the whole organization.', 403, extra: ['code' => 'restricted_view']),
            MockResponse::error("This token lacks the required 'heartbeats.declare' permission.", 403),
        ]);

        $raised = [];

        for ($i = 0; $i < 3; $i++) {
            try {
                $client->heartbeats()->declare(shopDeclaration());
            } catch (PermissionDeniedException $e) {
                $raised[] = $e;
            }
        }

        expect($raised)->toHaveCount(3)
            ->and($raised[0])->toBeInstanceOf(FeatureNotAvailableException::class)
            ->and($raised[0] instanceof FeatureNotAvailableException ? $raised[0]->featureKey() : null)->toBe('api_access')
            ->and($raised[1])->toBeInstanceOf(RestrictedViewException::class)
            ->and($raised[1]->status())->toBe(403)
            ->and($raised[1]->body()['code'] ?? null)->toBe(RestrictedViewException::CODE)
            ->and($raised[1]->errorMessage())->toBe('Declaring heartbeats requires a token that sees the whole organization.')
            ->and($raised[2]::class)->toBe(PermissionDeniedException::class);
    });

    it('sends a refused declaration once', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::error('Declaring heartbeats requires a token that sees the whole organization.', 403, extra: ['code' => 'restricted_view']),
        ]);

        expect(fn(): HeartbeatDeclarationResult => $client->heartbeats()->declare(shopDeclaration()))
            ->toThrow(RestrictedViewException::class);

        $http->assertSentCount(1);
    });

    it('surfaces a malformed declaration as a validation error on its entry', function (): void {
        $message = 'The key may contain letters, digits, ".", "_", "-", ":" and "/".';
        [$client] = singleShotClient([MockResponse::error($message, 422, ['heartbeats.1.key' => [$message]])]);

        try {
            $client->heartbeats()->declare(shopDeclaration());
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->firstError('heartbeats.1.key'))->toBe($message);
        }
    });

    it('does not repeat a declaration whose answer was a gateway error', function (): void {
        [$client, $http] = fakeClient([MockResponse::error('Bad gateway', 502)]);

        expect(fn(): HeartbeatDeclarationResult => $client->heartbeats()->declare(shopDeclaration()))->toThrow(ServerException::class);

        $http->assertSentCount(1);
    });

    it('gives up after the retries on a connection that never answers', function (): void {
        [$client, $http] = fakeClient([MockResponse::networkError(), MockResponse::networkError(), MockResponse::networkError()]);

        expect(fn(): HeartbeatDeclarationResult => $client->heartbeats()->declare(shopDeclaration()))->toThrow(TransportException::class);

        $http->assertSentCount(3);
    });
});

it('is one memoised resource on the client', function (): void {
    [$client] = fakeClient();

    expect($client->heartbeats())->toBe($client->heartbeats());
});
