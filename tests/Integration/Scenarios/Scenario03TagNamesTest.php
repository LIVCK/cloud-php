<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Query\TagQuery;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 3, tags by name: services created and updated with tag names, tag ids and both, the
 * spellings the server reads as one tag, and the two refusals: an unknown entry shaped like a
 * tag id, and another organization's tag id. Manual services only, so nothing waits for a probe.
 * Tags a write created by name are removed at the end with everything else.
 */
$skip = LiveApi::skipReason(LiveApi::TOKEN_VARIABLE, LiveApi::OTHER_ORG_TOKEN_VARIABLE);

describe('scenario 3: service tags by id or by name', function () use ($skip): void {
    it('creates and updates services with tag names and ids, and refuses unknown and foreign ids', function (): void {
        $client = LiveApi::client();
        $foreign = LiveApi::client(LiveApi::OTHER_ORG_TOKEN_VARIABLE);
        $journey = new Journey();
        $cleanup = new Cleanup();
        $key = Scenario::run();
        $cleanupFailures = [];
        $journey->note(sprintf('tag key %s', $key));

        /** @var array<string, true> $registered */
        $registered = [];
        $adopt = static function (Service $service) use ($client, $cleanup, &$registered): void {
            foreach ($service->tags ?? [] as $tag) {
                if (Scenario::isOurs($tag->key) && ! isset($registered[$tag->id])) {
                    $registered[$tag->id] = true;
                    $cleanup->add('tag ' . $tag->label, static fn() => $client->tags()->delete($tag->id));
                }
            }
        };

        /** @return list<string> */
        $labels = static function (Service $service): array {
            $labels = array_map(static fn(Tag $tag): string => $tag->label, $service->tags ?? []);
            sort($labels);

            return $labels;
        };

        try {
            $existing = $journey->step('1 an existing tag, through ensure()', static fn(): Tag => Scenario::customerTag($client, $cleanup, 'names'));
            $registered[$existing->id] = true;

            $first = $journey->step('2 create: new names, an existing name and its id, mixed', function () use ($client, $cleanup, $key, $existing, $adopt, $labels): Service {
                $service = Scenario::service($client, $cleanup, ServiceBuilder::manual(Scenario::name('tag-names'))->tags(
                    "{$key}:new-one",
                    "{$key}=new-two",
                    "{$key}-bare",
                    $existing->label,
                    $existing,
                    strtoupper($key) . ':customer-names',
                ));
                $adopt($service);

                expect($labels($service))->toBe(["{$key}-bare", $existing->label, "{$key}:new-one", "{$key}:new-two"])
                    ->and($service->tagIds())->toContain($existing->id);

                return $service;
            });

            $journey->step('3 `a:b` and `a=b` are one tag; a name that exists is reused', function () use ($client, $cleanup, $key, $first, $adopt, $labels): void {
                $service = Scenario::service($client, $cleanup, ServiceBuilder::manual(Scenario::name('tag-spellings'))->tags(
                    "{$key}:same",
                    "{$key}=same",
                    "{$key}=new-one",
                ));
                $adopt($service);

                $newOne = null;

                foreach ($first->tags ?? [] as $tag) {
                    if ($tag->label === "{$key}:new-one") {
                        $newOne = $tag;
                    }
                }

                expect($newOne)->not->toBeNull()
                    ->and($labels($service))->toBe(["{$key}:new-one", "{$key}:same"])
                    ->and($service->tagIds())->toContain($newOne?->id)
                    ->and($client->tags()->list(TagQuery::make()->withLabel("{$key}:same"))->total)->toBe(1)
                    ->and(DtoAudit::inspect($client->tags()->get((string) $newOne?->id), 'tags.get')->servicesCount)->toBe(2);
            });

            $journey->step('4 update: the whole set, by Tag and by a new name', function () use ($client, $key, $first, $existing, $adopt, $labels): void {
                $updated = DtoAudit::inspect(
                    $client->services()->update($first->id, UpdateService::make()->withTags($existing, "{$key}:new-three")),
                    'services.update',
                );
                $adopt($updated);

                expect($labels($updated))->toBe([$existing->label, "{$key}:new-three"])
                    ->and($labels($client->services()->get($first->id)))->toBe([$existing->label, "{$key}:new-three"]);
            });

            $journey->step('5 an unknown entry shaped like a tag id: refused on its entry, nothing created', function () use ($client, $key): void {
                $unknown = 'Zz' . substr(bin2hex(random_bytes(10)), 0, 19);

                try {
                    $client->services()->create(ServiceBuilder::manual(Scenario::name('unknown-id'))->tags("{$key}:never", $unknown));
                    expect(false)->toBeTrue('A create with an unknown tag id was accepted.');
                } catch (ValidationException $e) {
                    expect($e->status())->toBe(422)
                        ->and($e->hasError('tags.1'))->toBeTrue()
                        ->and($e->hasError('tags.0'))->toBeFalse()
                        ->and((string) $e->firstError('tags.1'))->toContain($unknown);
                }

                expect($client->tags()->findByLabel("{$key}:never"))->toBeNull();
            });

            $journey->step("6 another organization's tag: its id is unknown here, its name a tag of our own", function () use ($client, $foreign, $cleanup, $adopt): void {
                $foreignTag = Scenario::customerTag($foreign, $cleanup, 'foreign');

                try {
                    $client->services()->create(ServiceBuilder::manual(Scenario::name('foreign-id'))->tags($foreignTag->id));
                    expect(false)->toBeTrue("A create with another organization's tag id was accepted.");
                } catch (ValidationException $e) {
                    expect($e->status())->toBe(422)
                        ->and($e->hasError('tags.0'))->toBeTrue();
                }

                $service = Scenario::service($client, $cleanup, ServiceBuilder::manual(Scenario::name('foreign-name'))->tags($foreignTag->label));
                $adopt($service);

                $own = ($service->tags ?? [])[0] ?? null;

                expect($service->tags ?? [])->toHaveCount(1)
                    ->and($own?->label)->toBe($foreignTag->label)
                    ->and($own?->id)->not->toBe($foreignTag->id)
                    ->and($foreign->tags()->get($foreignTag->id)->servicesCount)->toBe(0);
            });

            $journey->step('7 a blank entry is refused before anything is sent', function (): void {
                $sent = LiveApi::attempts()->attempts();

                expect(static fn(): ServiceBuilder => ServiceBuilder::manual(Scenario::name('blank'))->tags('ok', ' '))->toThrow(InvalidArgumentException::class);
                expect(static fn(): UpdateService => UpdateService::make()->withTags(''))->toThrow(InvalidArgumentException::class);
                expect(LiveApi::attempts()->attempts())->toBe($sent, 'A client-side refusal sent a request.');
            });
        } finally {
            $cleanupFailures = $journey->step('8 cleanup: services, then the tags', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
