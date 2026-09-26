<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\Incident;
use LIVCK\Cloud\Data\Maintenance;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\Statuspage;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\AuthenticationException;
use LIVCK\Cloud\Exceptions\CatalogValidationException;
use LIVCK\Cloud\Exceptions\FeatureNotAvailableException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\PlanLimitException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Pagination\CursorPage;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Payloads\CreateStatuspage;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Query\IncidentQuery;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 9, errors: every kind of refusal the API makes, each as the exception the SDK
 * documents for it, with the details a caller reads from it.
 */
describe('scenario 9: errors', function (): void {
    $main = LiveApi::skipReason();

    it('reports validation errors on their field', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $catalog = $client->checkTypes();

        try {
            $journey->step('1 a malformed target, an interval below the plan, an unknown location, an unknown tag', function () use ($client): void {
                $cases = [
                    'target' => ServiceBuilder::http(Scenario::name('bad-target'), 'not a url'),
                    'settings.interval_seconds' => ServiceBuilder::http(Scenario::name('bad-interval'), 'https://example.com/')->interval(10),
                    'settings.assigned_probes.0' => ServiceBuilder::http(Scenario::name('bad-probe'), 'https://example.com/')->probes('zzz'),
                    'tags.0' => ServiceBuilder::manual(Scenario::name('bad-tag'))->tags(str_repeat('x', 21)),
                ];

                foreach ($cases as $field => $builder) {
                    try {
                        $service = $client->services()->create($builder);
                        $client->services()->delete($service->id);
                        expect(false)->toBeTrue(sprintf('The server accepted a payload that should fail on %s.', $field));
                    } catch (ValidationException $e) {
                        expect($e->status())->toBe(422)
                            ->and($e->hasFieldErrors())->toBeTrue($field)
                            ->and($e->hasError($field))->toBeTrue($field . ': ' . implode(', ', array_keys($e->errors())))
                            ->and($e->firstError($field))->not->toBeNull()
                            ->and($e->firstError())->not->toBeNull()
                            ->and($e->errorMessage())->not->toBe('')
                            ->and($e->getMessage())->toContain('HTTP 422');
                    }
                }
            });

            $journey->step('2 a malformed slug on a status page', function () use ($client): void {
                try {
                    $page = $client->statuspages()->create(CreateStatuspage::make('Bad slug')->withSlug('Bad Slug!'));
                    $client->statuspages()->delete($page->id);
                    expect(false)->toBeTrue('The server accepted a malformed slug.');
                } catch (ValidationException $e) {
                    expect($e->hasError('slug'))->toBeTrue();
                }
            });

            $journey->step('3 the catalog refuses an unknown option before sending', function () use ($client, $catalog): void {
                $sent = LiveApi::attempts()->attempts();

                try {
                    $client->services()->create(ServiceBuilder::http(Scenario::name('bad-option'), 'https://example.com/')->config('nope', true), $catalog);
                    expect(false)->toBeTrue('An unknown option passed the catalog.');
                } catch (CatalogValidationException $e) {
                    expect($e->problems())->toHaveCount(1)
                        ->and($e->problems()[0])->toContain('nope');
                }

                expect(LiveApi::attempts()->attempts())->toBe($sent);
            });
        } finally {
            Scenario::report($journey);
        }
    })->skip($main !== null, $main ?? '');

    $other = LiveApi::skipReason(LiveApi::TOKEN_VARIABLE, LiveApi::OTHER_ORG_TOKEN_VARIABLE);

    it('answers not found for unknown ids and for another organization\'s objects', function (): void {
        $client = LiveApi::client();
        $foreign = LiveApi::client(LiveApi::OTHER_ORG_TOKEN_VARIABLE);
        $journey = new Journey();
        $cleanup = new Cleanup();
        $cleanupFailures = [];

        try {
            $journey->step('1 random ids', function () use ($client): void {
                $id = bin2hex(random_bytes(10)) . 'x';

                expect(static fn(): Service => $client->services()->get($id))->toThrow(NotFoundException::class);
                expect(static fn(): Tag => $client->tags()->get($id))->toThrow(NotFoundException::class);
                expect(static fn(): Incident => $client->incidents()->get($id))->toThrow(NotFoundException::class);
                expect(static fn(): Maintenance => $client->maintenances()->get($id))->toThrow(NotFoundException::class);
                expect(static fn(): Statuspage => $client->statuspages()->get($id))->toThrow(NotFoundException::class);
                expect(static fn(): array => $client->statuspages()->components($id)->all())->toThrow(NotFoundException::class);
                expect(static fn(): CursorPage => $client->services()->checks($id))->toThrow(NotFoundException::class);

                try {
                    $client->services()->delete($id);
                    expect(false)->toBeTrue('Deleting an unknown service succeeded.');
                } catch (NotFoundException $e) {
                    expect($e->status())->toBe(404)
                        ->and($e->requestMethod())->toBe('DELETE')
                        ->and($e->requestUri())->toContain($id);
                }
            });

            $journey->step('2 another organization\'s service, tag and page read as not found; a foreign id in a filter matches nothing', function () use ($client, $foreign, $cleanup): void {
                $service = Scenario::manualService($foreign, $cleanup, 'foreign');
                $tag = Scenario::customerTag($foreign, $cleanup, 'foreign');
                $page = Scenario::statuspage($foreign, $cleanup, 'foreign');

                expect(DtoAudit::inspect($foreign->services()->get($service->id), 'services.get (other organization)')->id)->toBe($service->id);

                expect(static fn(): Service => $client->services()->get($service->id))->toThrow(NotFoundException::class);
                expect(static fn(): Service => $client->services()->update($service->id, UpdateService::make()->withName('taken over')))->toThrow(NotFoundException::class);
                expect(static fn(): Service => $client->services()->pause($service->id))->toThrow(NotFoundException::class);
                expect(static fn() => $client->services()->delete($service->id))->toThrow(NotFoundException::class);
                expect(static fn(): Page => $client->services()->incidents($service->id))->toThrow(NotFoundException::class);
                expect(static fn(): Tag => $client->tags()->get($tag->id))->toThrow(NotFoundException::class);
                expect(static fn(): Statuspage => $client->statuspages()->get($page->id))->toThrow(NotFoundException::class);
                expect(static fn(): array => $client->statuspages()->customDomains($page->id)->all())->toThrow(NotFoundException::class);

                expect($client->statuspages()->findBySlug($page->slug))->toBeNull()
                    ->and($client->tags()->findByLabel($tag->label))->toBeNull()
                    ->and($client->services()->list(ServiceQuery::make()->withTag($tag->label))->total)->toBe(0)
                    ->and($client->incidents()->list(IncidentQuery::make()->withServiceIds([$service->id]))->total)->toBe(0)
                    ->and($client->maintenances()->list(MaintenanceQuery::make()->withServiceIds([$service->id]))->total)->toBe(0)
                    ->and($foreign->services()->get($service->id)->name)->toBe($service->name);
            });
        } finally {
            $cleanupFailures = $journey->step('3 cleanup: the other organization\'s objects', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($other !== null, $other ?? '');

    it('refuses a bogus token', function (): void {
        $client = LiveApi::clientWithToken('lvk_' . bin2hex(random_bytes(24)), LiveApi::options(maxRetries: 0));

        try {
            $client->me();
            expect(false)->toBeTrue('A bogus token was accepted.');
        } catch (AuthenticationException $e) {
            expect($e->status())->toBe(401)
                ->and($e->getMessage())->not->toContain('lvk_')
                ->and($e->errorMessage())->not->toBe('');
        }

        expect(static fn(): Page => $client->services()->list())->toThrow(AuthenticationException::class);
    })->skip($main !== null, $main ?? '');

    $abilities = LiveApi::skipReason(LiveApi::TOKEN_VARIABLE, LiveApi::READONLY_TOKEN_VARIABLE, LiveApi::NO_INCIDENT_DELETE_TOKEN_VARIABLE);

    it('refuses a token without the ability and touches nothing', function (): void {
        $client = LiveApi::client();
        $readOnly = LiveApi::client(LiveApi::READONLY_TOKEN_VARIABLE);
        $noDelete = LiveApi::client(LiveApi::NO_INCIDENT_DELETE_TOKEN_VARIABLE);
        $journey = new Journey();
        $cleanup = new Cleanup();
        $cleanupFailures = [];

        try {
            $journey->step('1 the read-only token may list but not create', function () use ($readOnly): void {
                expect($readOnly->services()->list()->items)->toBeArray()
                    ->and($readOnly->probes())->not->toBe([]);

                try {
                    $service = $readOnly->services()->create(ServiceBuilder::manual(Scenario::name('forbidden')));
                    $readOnly->services()->delete($service->id);
                    expect(false)->toBeTrue('The read-only token created a service.');
                } catch (PermissionDeniedException $e) {
                    expect($e)->not->toBeInstanceOf(FeatureNotAvailableException::class)
                        ->and($e->status())->toBe(403)
                        ->and($e->errorMessage())->toContain('services.create');
                }

                expect(static fn() => $readOnly->tags()->ensure(Scenario::run(), 'forbidden'))->toThrow(PermissionDeniedException::class);
                expect(static fn(): Page => $readOnly->incidents()->list())->toThrow(PermissionDeniedException::class);
                expect(static fn(): Page => $readOnly->statuspages()->list())->toThrow(PermissionDeniedException::class);
            });

            $journey->step('2 erasing orphaned incidents or maintenances needs their delete ability; the service stays', function () use ($client, $noDelete, $cleanup): void {
                $service = Scenario::manualService($noDelete, $cleanup, 'no-delete');

                foreach ([['incidents.delete', true, false], ['maintenances.delete', false, true]] as [$ability, $incidents, $maintenances]) {
                    try {
                        $noDelete->services()->delete($service->id, deleteOrphanedIncidents: $incidents, deleteOrphanedMaintenances: $maintenances);
                        expect(false)->toBeTrue('The delete with ' . $ability . ' went through.');
                    } catch (PermissionDeniedException $e) {
                        expect($e->status())->toBe(403)
                            ->and($e->errorMessage())->toContain($ability);
                    }

                    expect($client->services()->get($service->id)->id)->toBe($service->id);
                }

                // Without the flags the same token deletes; cleanup then finds it gone.
                $noDelete->services()->delete($service->id);

                expect(static fn(): Service => $client->services()->get($service->id))->toThrow(NotFoundException::class);
            });
        } finally {
            $cleanupFailures = $journey->step('3 cleanup', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($abilities !== null, $abilities ?? '');

    $noApi = LiveApi::skipReason(LiveApi::NO_API_TOKEN_VARIABLE);

    it('tells a plan without API access which feature is missing', function (): void {
        $client = LiveApi::client(LiveApi::NO_API_TOKEN_VARIABLE);
        $me = DtoAudit::inspect($client->me(), 'me (plan without API access)');

        expect($me->organization->name)->not->toBe('');

        try {
            $client->services()->list();
            expect(false)->toBeTrue('A plan without API access listed services.');
        } catch (FeatureNotAvailableException $e) {
            expect($e->featureKey())->toBe('api_access')
                ->and($e)->toBeInstanceOf(PermissionDeniedException::class)
                ->and($e->status())->toBe(403);
        }

        expect(static fn(): Service => $client->services()->create(ServiceBuilder::manual(Scenario::name('gated'))))->toThrow(FeatureNotAvailableException::class);
        expect(static fn(): Page => $client->tags()->list())->toThrow(FeatureNotAvailableException::class);
    })->skip($noApi !== null, $noApi ?? '');

    it('reports the service limit as a plan limit with key, limit and usage', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $cleanupFailures = [];

        try {
            $journey->step('1 fill the organization up to its limit of 30 services', function () use ($client, $cleanup, $journey): void {
                $counting = 0;

                foreach ($client->services()->each(ServiceQuery::make()->withPerPage(100)) as $service) {
                    if (! $service->isPaused && $service->checkType->value !== 'agent') {
                        $counting++;
                    }
                }

                $missing = 30 - $counting;

                for ($i = 1; $i <= $missing; $i++) {
                    Scenario::manualService($client, $cleanup, sprintf('fill-%02d', $i));
                }

                $journey->note(sprintf('%d services counted before, %d fillers created', $counting, max(0, $missing)));
            });

            $journey->step('2 the 31st create is a plan limit: services, 30 of 30', function () use ($client): void {
                try {
                    $service = $client->services()->create(ServiceBuilder::manual(Scenario::name('one-too-many')));
                    $client->services()->delete($service->id);
                    expect(false)->toBeTrue('The 31st service was created.');
                } catch (PlanLimitException $e) {
                    expect($e->limitKey())->toBe('services')
                        ->and($e->limit())->toBe(30)
                        ->and($e->usage())->toBe(30)
                        ->and($e->status())->toBe(403)
                        ->and($e)->not->toBeInstanceOf(PermissionDeniedException::class);
                }
            });

            $journey->step('3 a paused service frees its slot; resuming it at the limit is the same refusal', function () use ($client, $cleanup): void {
                $paused = null;

                foreach ($client->services()->each(ServiceQuery::make()->withPerPage(100)) as $service) {
                    if (str_starts_with($service->name, Scenario::name('fill-'))) {
                        $paused = $service;

                        break;
                    }
                }

                expect($paused)->toBeInstanceOf(Service::class, 'No filler of this run found.');

                if (! $paused instanceof Service) {
                    return;
                }

                $client->services()->pause($paused->id);
                $extra = Scenario::manualService($client, $cleanup, 'fill-extra');

                expect($extra->isPaused)->toBeFalse();

                try {
                    $client->services()->resume($paused->id);
                    expect(false)->toBeTrue('A resume beyond the limit went through.');
                } catch (PlanLimitException $e) {
                    expect($e->limitKey())->toBe('services')
                        ->and($e->limit())->toBe(30)
                        ->and($e->usage())->toBe(30);
                }

                expect($client->services()->get($paused->id)->isPaused)->toBeTrue();
            });
        } finally {
            $cleanupFailures = $journey->step('4 cleanup: the fillers', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($main !== null, $main ?? '');

    it('honours an explicit idempotency key: replay, conflict, refusal', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $cleanupFailures = [];

        try {
            $journey->step('1 the same key twice creates one service; the second answer is a replay', function () use ($client, $cleanup): void {
                $key = Scenario::name('idempotency');
                $builder = ServiceBuilder::manual(Scenario::name('idempotent'));
                $first = DtoAudit::inspect($client->services()->create($builder, null, $key), 'services.create (with key)');
                $cleanup->add('service ' . $first->name, static fn() => $client->services()->delete($first->id));

                $replay = $client->send(Request::post('services', $builder->toArray())->withIdempotencyKey($key));
                $again = $client->services()->create($builder, null, $key);
                $named = 0;

                foreach ($client->services()->each(ServiceQuery::make()->withPerPage(100)) as $service) {
                    if ($service->name === $builder->name()) {
                        $named++;
                    }
                }

                expect($replay->status())->toBe(201)
                    ->and($replay->wasReplayed())->toBeTrue()
                    ->and(LiveApi::data($replay)['id'] ?? null)->toBe($first->id)
                    ->and($again->id)->toBe($first->id)
                    ->and($named)->toBe(1);
            });

            $journey->step('2 the same key with a different body is a 422 without field errors', function () use ($client): void {
                $key = Scenario::name('idempotency');

                try {
                    $service = $client->services()->create(ServiceBuilder::manual(Scenario::name('idempotent-other')), null, $key);
                    $client->services()->delete($service->id);
                    expect(false)->toBeTrue('A different body was accepted under a used key.');
                } catch (ValidationException $e) {
                    expect($e->status())->toBe(422)
                        ->and($e->hasFieldErrors())->toBeFalse()
                        ->and($e->errors())->toBe([])
                        ->and($e->firstError())->toBeNull()
                        ->and($e->errorMessage())->toContain('Idempotency-Key');
                }
            });

            $journey->step('3 a malformed key is refused before sending', function () use ($client): void {
                $sent = LiveApi::attempts()->attempts();

                expect(static fn(): Service => $client->services()->create(ServiceBuilder::manual(Scenario::name('x')), null, 'bad key'))->toThrow(InvalidArgumentException::class);
                expect(static fn(): Service => $client->services()->create(ServiceBuilder::manual(Scenario::name('x')), null, ''))->toThrow(InvalidArgumentException::class);
                expect(static fn(): Service => $client->services()->create(ServiceBuilder::manual(Scenario::name('x')), null, str_repeat('k', 256)))->toThrow(InvalidArgumentException::class);
                expect(static fn(): Request => Request::get('services')->withIdempotencyKey('key'))->toThrow(InvalidArgumentException::class);
                expect(LiveApi::attempts()->attempts())->toBe($sent);
            });
        } finally {
            $cleanupFailures = $journey->step('4 cleanup', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($main !== null, $main ?? '');
});
