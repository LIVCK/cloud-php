<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\EnsuredTag;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\TagSource;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Payloads\UpdateTag;
use LIVCK\Cloud\Query\TagQuery;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 2, tags at scale: 25 customer tags through ensure(), exact lookups, paging,
 * updates, deletion and the refusals. Everything carries the run prefix and is removed at
 * the end, whatever happened.
 */
$skip = LiveApi::skipReason();

describe('scenario 2: tags at scale', function () use ($skip): void {
    it('creates, finds, pages, updates and deletes 25 customer tags', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $key = Scenario::run();
        $cleanupFailures = [];
        $journey->note(sprintf('tag key %s', $key));

        try {
            /** @var array<string, Tag> $tags */
            $tags = $journey->step('1 25 × ensure(): 201 on the first call, 200 unchanged on the repeat', function () use ($client, $cleanup, $key): array {
                $tags = [];

                for ($i = 1; $i <= 25; $i++) {
                    $value = sprintf('customer-%02d', $i);
                    $color = $i % 5 === 0 ? '#6366f1' : null;
                    $first = DtoAudit::inspect($client->tags()->ensure($key, $value, $color), 'tags.ensure');
                    $tag = $first->tag;
                    $cleanup->add('tag ' . $tag->label, static fn() => $client->tags()->delete($tag->id));

                    expect($first->wasCreated())->toBeTrue($value)
                        ->and($first->created)->toBeTrue()
                        ->and($tag->key)->toBe($key)
                        ->and($tag->value)->toBe($value)
                        ->and($tag->hasValue())->toBeTrue()
                        ->and($tag->label)->toBe($key . ':' . $value)
                        ->and($tag->source)->toBe(TagSource::User)
                        ->and($tag->isSystem())->toBeFalse()
                        ->and($tag->servicesCount)->toBe(0)
                        ->and($tag->color)->toMatch('/\A#[0-9a-f]{6}\z/i');

                    if ($color !== null) {
                        expect($tag->color)->toBe($color);
                    }

                    $again = DtoAudit::inspect($client->tags()->ensure($key, $value, '#000000'), 'tags.ensure (repeat)');

                    expect($again->existed())->toBeTrue($value)
                        ->and($again->created)->toBeFalse()
                        ->and($again->tag->id)->toBe($tag->id)
                        ->and($again->tag->color)->toBe($tag->color);

                    $tags[$value] = $tag;
                }

                return $tags;
            });

            $journey->step('2 findByLabel() and get()', function () use ($client, $key, $tags): void {
                $wanted = $tags['customer-07'];
                $found = DtoAudit::inspect($client->tags()->findByLabel($key . ':customer-07'), 'tags.findByLabel');
                $upperKey = DtoAudit::inspect($client->tags()->findByLabel(strtoupper($key) . ':customer-07'), 'tags.findByLabel (upper-case key)');

                expect($found?->id)->toBe($wanted->id)
                    ->and($upperKey?->id)->toBe($wanted->id)
                    ->and($client->tags()->findByLabel($key . ':CUSTOMER-07'))->toBeNull()
                    ->and($client->tags()->findByLabel($key . ':customer-99'))->toBeNull()
                    ->and($client->tags()->findByLabel($key))->toBeNull()
                    ->and(DtoAudit::inspect($client->tags()->get($wanted->id), 'tags.get')->label)->toBe($wanted->label);
            });

            $journey->step('3 list by key, 10 per page over 3 pages; each() yields exactly 25', function () use ($client, $key, $tags, $journey): void {
                $first = DtoAudit::inspect($client->tags()->list(TagQuery::make()->withKey($key)->withPerPage(10)), 'tags.list');

                expect($first->total)->toBe(25)
                    ->and($first->lastPage)->toBe(3)
                    ->and($first->perPage)->toBe(10)
                    ->and($first->currentPage)->toBe(1)
                    ->and($first->from)->toBe(1)
                    ->and($first->to)->toBe(10)
                    ->and(count($first))->toBe(10)
                    ->and($first->hasMorePages())->toBeTrue();

                $second = $first->nextPage();
                $third = $second?->nextPage();

                expect($second?->currentPage)->toBe(2)
                    ->and($second === null ? 0 : count($second))->toBe(10)
                    ->and($third?->currentPage)->toBe(3)
                    ->and($third === null ? 0 : count($third))->toBe(5)
                    ->and($third?->hasMorePages())->toBeFalse()
                    ->and($third?->nextPage())->toBeNull();

                $ids = [];

                foreach ($client->tags()->each(TagQuery::make()->withKey($key)->withPerPage(10)) as $tag) {
                    $ids[] = DtoAudit::inspect($tag, 'tags.each')->id;
                }

                expect($ids)->toHaveCount(25)
                    ->and(array_unique($ids))->toHaveCount(25)
                    ->and($ids)->toEqualCanonicalizing(array_map(static fn(Tag $tag): string => $tag->id, array_values($tags)));

                $byLabel = $client->tags()->list(TagQuery::make()->withLabel($key . ':customer-01'));
                $unknownKey = $client->tags()->list(TagQuery::make()->withKey($key . '-nobody'));

                expect($byLabel->total)->toBe(1)
                    ->and($byLabel->first()?->id)->toBe($tags['customer-01']->id)
                    ->and($unknownKey->total)->toBe(0)
                    ->and($unknownKey->isEmpty())->toBeTrue();

                $journey->note(sprintf('pages of %d: %d, %d, %d items; each() yielded %d', $first->perPage, count($first), $second === null ? 0 : count($second), $third === null ? 0 : count($third), count($ids)));
            });

            $journey->step('4 update value and color, drop the value, delete', function () use ($client, $key, $tags): void {
                $tag = $tags['customer-25'];
                $updated = DtoAudit::inspect($client->tags()->update($tag->id, UpdateTag::make()->withValue('customer-25b')->withColor('#22c55e')), 'tags.update');

                expect($updated->id)->toBe($tag->id)
                    ->and($updated->value)->toBe('customer-25b')
                    ->and($updated->label)->toBe($key . ':customer-25b')
                    ->and($updated->color)->toBe('#22c55e')
                    ->and($client->tags()->get($tag->id)->label)->toBe($key . ':customer-25b');

                $bare = DtoAudit::inspect($client->tags()->update($tag->id, UpdateTag::make()->withoutValue()), 'tags.update (without value)');

                expect($bare->value)->toBeNull()
                    ->and($bare->hasValue())->toBeFalse()
                    ->and($bare->label)->toBe($key)
                    ->and($client->tags()->findByLabel($key)?->id)->toBe($tag->id);

                // A rename onto an existing key/value pair is refused.
                expect(static fn(): Tag => $client->tags()->update($tags['customer-24']->id, UpdateTag::make()->withValue('customer-23')))
                    ->toThrow(ValidationException::class);

                $client->tags()->delete($tag->id);

                expect(static fn(): Tag => $client->tags()->get($tag->id))->toThrow(NotFoundException::class);
                expect($client->tags()->list(TagQuery::make()->withKey($key)->withPerPage(100))->total)->toBe(24);
            });

            $journey->step('5 create() of an existing label is a 422 on key', function () use ($client, $key): void {
                try {
                    $client->tags()->create($key, 'customer-01');
                    expect(false)->toBeTrue('A duplicate create() was accepted.');
                } catch (ValidationException $e) {
                    expect($e->status())->toBe(422)
                        ->and($e->hasFieldErrors())->toBeTrue()
                        ->and($e->hasError('key'))->toBeTrue()
                        ->and($e->firstError('key'))->not->toBeNull()
                        ->and($e->errorMessage())->not->toBe('');
                }
            });

            $journey->step('6 blank label, key, color or an empty update are refused with nothing sent', function () use ($client, $tags): void {
                $sent = LiveApi::attempts()->attempts();
                $id = $tags['customer-01']->id;

                expect(static fn(): Tag => $client->tags()->create(' '))->toThrow(InvalidArgumentException::class);
                expect(static fn(): EnsuredTag => $client->tags()->ensure(''))->toThrow(InvalidArgumentException::class);
                expect(static fn(): ?Tag => $client->tags()->findByLabel("\t"))->toThrow(InvalidArgumentException::class);
                expect(static fn(): TagQuery => TagQuery::make()->withKey(''))->toThrow(InvalidArgumentException::class);
                expect(static fn(): TagQuery => TagQuery::make()->withLabel(' '))->toThrow(InvalidArgumentException::class);
                expect(static fn(): TagQuery => TagQuery::make()->withPerPage(101))->toThrow(InvalidArgumentException::class);
                expect(static fn(): UpdateTag => UpdateTag::make()->withColor('indigo'))->toThrow(InvalidArgumentException::class);
                expect(static fn(): Tag => $client->tags()->update($id, UpdateTag::make()))->toThrow(InvalidArgumentException::class);
                expect(static fn(): Tag => $client->tags()->create('ok', null, null, 'bad key'))->toThrow(InvalidArgumentException::class);

                expect(LiveApi::attempts()->attempts())->toBe($sent, 'A client-side refusal sent a request.');
            });
        } finally {
            $cleanupFailures = $journey->step('7 cleanup: the tags', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
