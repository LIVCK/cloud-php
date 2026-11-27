<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\HeartbeatBuilder;
use LIVCK\Cloud\Builders\HeartbeatDeclaration;
use LIVCK\Cloud\Builders\HeartbeatSchedule;
use LIVCK\Cloud\Data\HeartbeatDeclarationResult;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\ServiceHeartbeat;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\HeartbeatDeclarationOutcome;
use LIVCK\Cloud\Enums\HeartbeatKind;
use LIVCK\Cloud\Enums\HeartbeatScheduleType;
use LIVCK\Cloud\Enums\ServiceStatus;
use LIVCK\Cloud\Exceptions\FeatureNotAvailableException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\RestrictedViewException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Support\Secret;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 10, heartbeats declared from code: an application of this run declares a nightly cron
 * job and a queue in one environment. The first declaration creates both and hands out their
 * ping URLs, and each is a heartbeat service whose `heartbeat` block names the application,
 * environment and key and which carries the system tags `livck.app` and `livck.env`; the same
 * declaration again changes nothing; a changed one aligns what was sent and
 * keeps what was left out (the run time limit: set, kept by omission, removed with an explicit
 * null), and a period below the plan's shortest comes back raised. A declaration without the
 * queue names it as unlisted and leaves it running. A ping reaches the nightly job's URL, when
 * that URL can be reached from where the suite runs. A malformed key is a validation error on
 * its entry; a token without the ability, a token whose view access areas limit and a plan without
 * API access are told apart when their tokens are set. The heartbeats are services and are deleted
 * at the end; their system tags stay until the server's clean-up removes them once unused.
 */
$skip = LiveApi::skipReason();

describe('scenario 10: heartbeats declared from code', function () use ($skip): void {
    it('creates, aligns and reports declared heartbeats, and never removes one', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $app = Scenario::run();
        $environment = 'e2e';
        $nightlyName = Scenario::name('hb-nightly');
        $queueName = Scenario::name('hb-queue');
        $cleanupFailures = [];

        $nightly = static fn(): HeartbeatBuilder => HeartbeatBuilder::schedule('export:nightly', HeartbeatSchedule::cron('0 3 * * *', 'Europe/Berlin'))->name($nightlyName);
        $queue = static fn(): HeartbeatBuilder => HeartbeatBuilder::queue('queue:default', HeartbeatSchedule::every(300))->name($queueName);
        $declare = static fn(HeartbeatBuilder ...$heartbeats): HeartbeatDeclarationResult => DtoAudit::inspect(
            $client->heartbeats()->declare(HeartbeatDeclaration::of($app, $environment, ...$heartbeats)),
            'heartbeats.declare',
        );

        try {
            $first = $journey->step('1 the first declaration creates both, with ping URLs', function () use ($declare, $nightly, $queue, $client, $cleanup, $nightlyName, $queueName): HeartbeatDeclarationResult {
                $result = $declare($nightly(), $queue());

                // Registered first, so that nothing a failed expectation stops stays behind.
                foreach ($result->heartbeats as $heartbeat) {
                    $serviceId = $heartbeat->serviceId;
                    $cleanup->add('heartbeat ' . $heartbeat->key, static fn() => $client->services()->delete($serviceId));
                }

                $export = $result->heartbeat('export:nightly');
                $worker = $result->heartbeat('queue:default');

                expect($result->allExist())->toBeTrue()
                    ->and($result->notCreated->reason)->toBeNull()
                    ->and($result->unlisted)->toBe([])
                    ->and($result->counts->created)->toBe(2)
                    ->and($export?->result)->toBe(HeartbeatDeclarationOutcome::Created)
                    ->and($export?->name)->toBe($nightlyName)
                    ->and($export?->kind)->toBe(HeartbeatKind::Schedule)
                    ->and($export?->schedule->type)->toBe(HeartbeatScheduleType::Cron)
                    ->and($export?->schedule->expression)->toBe('0 3 * * *')
                    ->and($export?->schedule->timezone)->toBe('Europe/Berlin')
                    ->and($export?->status)->toBe(ServiceStatus::Unknown)
                    ->and($export?->paused)->toBeFalse()
                    ->and($export?->maxDurationSeconds)->toBeNull()
                    ->and($worker?->name)->toBe($queueName)
                    ->and($worker?->schedule->periodSeconds)->toBe(300)
                    ->and($worker?->schedule->timezone)->toBe('UTC')
                    ->and(array_keys($result->pingUrls()))->toBe(['export:nightly', 'queue:default'])
                    ->and($export?->pingUrl)->toBeInstanceOf(Secret::class)
                    ->and((string) json_encode($result))->not->toContain($export?->pingUrl?->reveal() ?? 'unset')
                    ->and($export?->raw)->not->toHaveKey('ping_url');

                return $result;
            });

            $journey->step('2 a declared heartbeat is a heartbeat service with its block and two system tags', function () use ($client, $first, $app, $environment): void {
                $export = $first->heartbeat('export:nightly');
                $service = DtoAudit::inspect($client->services()->get((string) $export?->serviceId), 'services.get');
                $heartbeat = $service->heartbeat;
                $systemLabels = array_values(array_map(
                    static fn(Tag $tag): string => $tag->label,
                    array_filter($service->tags ?? [], static fn(Tag $tag): bool => $tag->isSystem()),
                ));
                sort($systemLabels);

                expect($service->checkType)->toBe(CheckType::Heartbeat)
                    ->and($service->name)->toBe($export?->name)
                    ->and($heartbeat)->toBeInstanceOf(ServiceHeartbeat::class)
                    ->and($heartbeat?->isDeclared())->toBeTrue()
                    ->and($heartbeat?->declared?->app)->toBe($app)
                    ->and($heartbeat?->declared?->environment)->toBe($environment)
                    ->and($heartbeat?->declared?->key)->toBe('export:nightly')
                    ->and($heartbeat?->declared?->kind)->toBe(HeartbeatKind::Schedule)
                    ->and($heartbeat?->schedule->type)->toBe(HeartbeatScheduleType::Cron)
                    ->and($heartbeat?->schedule->expression)->toBe('0 3 * * *')
                    ->and($heartbeat?->schedule->timezone)->toBe('Europe/Berlin')
                    ->and($heartbeat?->graceSeconds)->toBe($export?->graceSeconds)
                    ->and($heartbeat?->scheduleTolerance)->toBe($export?->scheduleTolerance)
                    ->and($heartbeat?->maxDurationSeconds)->toBeNull()
                    ->and($heartbeat?->lastPingAt)->toBeNull()
                    ->and($systemLabels)->toBe(['livck.app:' . $app, 'livck.env:' . $environment])
                    ->and((string) json_encode($service->raw))->not->toContain($export?->pingUrl?->reveal() ?? 'unset');

                // The list carries the block as well, and finds the application by its system tag.
                $listed = DtoAudit::inspect($client->services()->list(ServiceQuery::make()->withTag('livck.app:' . $app)), 'services.list');
                $keys = array_map(static fn(Service $s): ?string => $s->heartbeat?->declared?->key, $listed->items);
                sort($keys);

                expect($keys)->toBe(['export:nightly', 'queue:default']);
            });

            $journey->step('3 the same declaration again writes nothing', function () use ($declare, $nightly, $queue, $first): void {
                $again = $declare($nightly(), $queue());

                expect($again->counts->unchanged)->toBe(2)
                    ->and($again->counts->changedAnything())->toBeFalse()
                    ->and(array_map(static fn($h) => $h->result, $again->heartbeats))->toBe([HeartbeatDeclarationOutcome::Unchanged, HeartbeatDeclarationOutcome::Unchanged])
                    ->and($again->heartbeat('export:nightly')?->serviceId)->toBe($first->heartbeat('export:nightly')?->serviceId)
                    ->and($again->heartbeat('export:nightly')?->pingUrl?->reveal())->toBe($first->heartbeat('export:nightly')?->pingUrl?->reveal());
            });

            $journey->step('4 a changed declaration aligns what was sent; a too short period is raised', function () use ($declare, $nightly, $queueName, $journey): void {
                $changed = $declare(
                    $nightly()->grace(300)->maxDuration(1800)->name('ignored after creation'),
                    HeartbeatBuilder::queue('queue:default', HeartbeatSchedule::every(1))->name($queueName),
                );
                $export = $changed->heartbeat('export:nightly');
                $worker = $changed->heartbeat('queue:default');

                expect($export?->result)->toBe(HeartbeatDeclarationOutcome::Updated)
                    ->and($export?->graceSeconds)->toBe(300)
                    ->and($export?->maxDurationSeconds)->toBe(1800)
                    ->and($export?->name)->not->toBe('ignored after creation')
                    ->and($worker?->result)->toBe(HeartbeatDeclarationOutcome::Updated)
                    ->and($worker?->schedule->periodSeconds)->toBeGreaterThan(1);

                $journey->note(sprintf('a period of 1 s came back as %d s', $worker?->schedule->periodSeconds ?? 0));
            });

            $journey->step('5 the run time limit: kept when left out, removed with null', function () use ($declare, $nightly, $queue): void {
                $kept = $declare($nightly()->grace(300), $queue());
                $removed = $declare($nightly()->grace(300)->noMaxDuration(), $queue());

                expect($kept->heartbeat('export:nightly')?->maxDurationSeconds)->toBe(1800)
                    ->and($kept->heartbeat('export:nightly')?->graceSeconds)->toBe(300)
                    ->and($removed->heartbeat('export:nightly')?->result)->toBe(HeartbeatDeclarationOutcome::Updated)
                    ->and($removed->heartbeat('export:nightly')?->maxDurationSeconds)->toBeNull();
            });

            $journey->step('6 a declaration without the queue lists it as unlisted and leaves it running', function () use ($declare, $nightly, $client, $first): void {
                $without = $declare($nightly()->grace(300)->noMaxDuration());
                $queueId = (string) $first->heartbeat('queue:default')?->serviceId;
                $service = DtoAudit::inspect($client->services()->get($queueId), 'services.get');

                expect($without->heartbeats)->toHaveCount(1)
                    ->and($without->unlistedKeys())->toBe(['queue:default'])
                    ->and($without->unlisted[0]->serviceId)->toBe($queueId)
                    ->and($without->heartbeat('queue:default'))->toBeNull()
                    ->and($service->isPaused)->toBeFalse();
            });

            $journey->step('7 the nightly job pings its URL', function () use ($client, $first, $journey): void {
                $url = $first->heartbeat('export:nightly')?->pingUrl;

                expect($url)->toBeInstanceOf(Secret::class);

                $result = $client->heartbeatPing($url ?? new Secret('https://unset.invalid/x'))->success();

                if ($result->noResponse) {
                    $journey->note('the ping URL is not reachable from here: ' . $result->error);

                    return;
                }

                expect($result->ok())->toBeTrue($result->error ?? '')
                    ->and($result->noResponse)->toBeFalse();
            });

            $journey->step('8 a malformed key is a validation error on its entry; a blank one is refused here', function () use ($client, $app, $environment): void {
                try {
                    $client->heartbeats()->declare(HeartbeatDeclaration::of($app, $environment, HeartbeatBuilder::job('-not valid-', HeartbeatSchedule::every(3600))));
                    expect(false)->toBeTrue('A malformed key was accepted.');
                } catch (ValidationException $e) {
                    expect($e->hasError('heartbeats.0.key'))->toBeTrue();
                }

                $sent = LiveApi::attempts()->attempts();

                expect(static fn(): HeartbeatBuilder => HeartbeatBuilder::job(' ', HeartbeatSchedule::every(60)))->toThrow(InvalidArgumentException::class);
                expect(LiveApi::attempts()->attempts())->toBe($sent, 'A client-side refusal sent a request.');
            });

            $journey->step('9 a token without the ability, a token with a limited view, and a plan without API access', function () use ($journey): void {
                $declaration = HeartbeatDeclaration::of(Scenario::run(), 'e2e', HeartbeatBuilder::job('denied', HeartbeatSchedule::every(3600)));

                if (LiveApi::skipReason(LiveApi::READONLY_TOKEN_VARIABLE) === null) {
                    try {
                        LiveApi::client(LiveApi::READONLY_TOKEN_VARIABLE)->heartbeats()->declare($declaration);
                        expect(false)->toBeTrue('A read-only token declared heartbeats.');
                    } catch (PermissionDeniedException $e) {
                        expect($e)->not->toBeInstanceOf(FeatureNotAvailableException::class)
                            ->and($e)->not->toBeInstanceOf(RestrictedViewException::class);
                    }
                } else {
                    $journey->note(LiveApi::READONLY_TOKEN_VARIABLE . ' is not set: the refused token is not tried');
                }

                if (LiveApi::skipReason(LiveApi::RESTRICTED_VIEW_TOKEN_VARIABLE) === null) {
                    try {
                        LiveApi::client(LiveApi::RESTRICTED_VIEW_TOKEN_VARIABLE)->heartbeats()->declare($declaration);
                        expect(false)->toBeTrue('A token with a limited view declared heartbeats.');
                    } catch (RestrictedViewException $e) {
                        expect($e->status())->toBe(403)
                            ->and($e->body()['code'] ?? null)->toBe(RestrictedViewException::CODE);
                    }
                } else {
                    $journey->note(LiveApi::RESTRICTED_VIEW_TOKEN_VARIABLE . ' is not set: the token with a limited view is not tried');
                }

                if (LiveApi::skipReason(LiveApi::NO_API_TOKEN_VARIABLE) === null) {
                    expect(static fn(): HeartbeatDeclarationResult => LiveApi::client(LiveApi::NO_API_TOKEN_VARIABLE)->heartbeats()->declare($declaration))
                        ->toThrow(FeatureNotAvailableException::class);
                } else {
                    $journey->note(LiveApi::NO_API_TOKEN_VARIABLE . ' is not set: the plan without API access is not tried');
                }
            });
        } finally {
            $cleanupFailures = $journey->step('10 cleanup: the declared heartbeats', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
