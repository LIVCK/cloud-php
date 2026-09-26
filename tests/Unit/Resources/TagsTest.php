<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\EnsuredTag;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\TagSource;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Payloads\UpdateTag;
use LIVCK\Cloud\Query\TagQuery;
use LIVCK\Cloud\Resources\TagsInterface;
use LIVCK\Cloud\Support\Uuid;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

describe('list', function (): void {
    it('lists tags as a page of hydrated DTOs', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([tagPayload(), tagPayload(['id' => 'second', 'key' => 'critical', 'value' => null, 'label' => 'critical', 'source' => 'system'])])]);

        $page = $client->tags()->list();

        expect($page)->toBeInstanceOf(Page::class)
            ->and($page->total)->toBe(2)
            ->and($page->items[0])->toBeInstanceOf(Tag::class)
            ->and($page->items[0]->id)->toBe('V1StGXR8Z5jdHi6BmyT01')
            ->and($page->items[0]->key)->toBe('kunde')
            ->and($page->items[0]->value)->toBe('4711')
            ->and($page->items[0]->label)->toBe('kunde:4711')
            ->and($page->items[0]->color)->toBe('#6366f1')
            ->and($page->items[0]->source)->toBe(TagSource::User)
            ->and($page->items[0]->servicesCount)->toBe(3)
            ->and($page->items[0]->hasValue())->toBeTrue()
            ->and($page->items[0]->isSystem())->toBeFalse()
            ->and($page->items[0]->raw)->toBe(tagPayload())
            ->and($page->items[1]->value)->toBeNull()
            ->and($page->items[1]->hasValue())->toBeFalse()
            ->and($page->items[1]->isSystem())->toBeTrue();

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/tags') && $r->queryString() === '');
    });

    it('sends the filters and paging of a query', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([])]);

        $client->tags()->list(TagQuery::make()->withLabel('kunde:4711')->withKey('kunde')->withPage(2)->withPerPage(10));

        expect($http->lastRequest()?->query())->toBe(['label' => 'kunde:4711', 'key' => 'kunde', 'page' => '2', 'per_page' => '10']);
    });

    it('keeps an unknown source readable', function (): void {
        [$client] = fakeClient([MockResponse::page([tagPayload(['source' => 'imported'])])]);

        $tag = $client->tags()->list()->first();

        expect($tag?->source)->toBe(TagSource::Unrecognized)
            ->and($tag?->source->isUnrecognized())->toBeTrue()
            ->and($tag?->raw['source'])->toBe('imported');
    });

    it('follows the pages with the same filters', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::page([tagPayload(['id' => 'p1'])], currentPage: 1, lastPage: 2, perPage: 1),
            MockResponse::page([tagPayload(['id' => 'p2'])], currentPage: 2, lastPage: 2, perPage: 1),
        ]);

        $first = $client->tags()->list(TagQuery::make()->withKey('kunde')->withPerPage(1));
        $second = $first->nextPage();

        expect($second?->items[0]->id)->toBe('p2')
            ->and($second?->hasMorePages())->toBeFalse()
            ->and($http->recorded()[1]->query())->toBe(['key' => 'kunde', 'page' => '2', 'per_page' => '1']);
    });
});

describe('each', function (): void {
    it('yields every tag across all pages, requesting the next page only when needed', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::page([tagPayload(['id' => 'a']), tagPayload(['id' => 'b'])], currentPage: 1, lastPage: 2, perPage: 2),
            MockResponse::page([tagPayload(['id' => 'c'])], currentPage: 2, lastPage: 2, perPage: 2),
        ]);

        $ids = [];

        foreach ($client->tags()->each() as $tag) {
            $ids[] = $tag->id;

            if ($tag->id === 'b') {
                expect($http->recorded())->toHaveCount(1);
            }
        }

        expect($ids)->toBe(['a', 'b', 'c'])
            ->and($http->recorded())->toHaveCount(2)
            ->and($http->recorded()[1]->query()['page'])->toBe('2');
    });
});

describe('get', function (): void {
    it('fetches one tag by id, percent-encoded in the path', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => tagPayload()])]);

        $tag = $client->tags()->get('V1StGXR8Z5jdHi6BmyT01');

        expect($tag->id)->toBe('V1StGXR8Z5jdHi6BmyT01');
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/tags/V1StGXR8Z5jdHi6BmyT01'));

        [$client, $http] = fakeClient([MockResponse::json(['data' => tagPayload()])]);
        $client->tags()->get('odd/id');

        expect($http->lastRequest()?->uri)->toBe('https://api.livck.cloud/v1/tags/odd%2Fid');
    });

    it('raises not found', function (): void {
        [$client] = singleShotClient([MockResponse::error('The requested resource was not found.', 404)]);

        expect(fn(): Tag => $client->tags()->get('missing'))->toThrow(NotFoundException::class);
    });
});

describe('findByLabel', function (): void {
    it('asks for exactly one tag by label and returns it', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([tagPayload()])]);

        $tag = $client->tags()->findByLabel('kunde:4711');

        expect($tag?->label)->toBe('kunde:4711')
            ->and($http->lastRequest()?->query())->toBe(['label' => 'kunde:4711', 'per_page' => '1']);
    });

    it('returns null when nothing matches', function (): void {
        [$client] = fakeClient([MockResponse::page([])]);

        expect($client->tags()->findByLabel('kunde:0000'))->toBeNull();
    });

    it('refuses a blank label before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): ?Tag => $client->tags()->findByLabel('  '))->toThrow(InvalidArgumentException::class, 'blank');
        $http->assertNothingSent();
    });
});

describe('ensure', function (): void {
    it('reports a created tag on 201', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => tagPayload()], 201)]);

        $result = $client->tags()->ensure('kunde', '4711', '#6366f1');

        expect($result)->toBeInstanceOf(EnsuredTag::class)
            ->and($result->created)->toBeTrue()
            ->and($result->wasCreated())->toBeTrue()
            ->and($result->existed())->toBeFalse()
            ->and($result->tag->label)->toBe('kunde:4711');

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/tags/ensure')
            && $r->json() === ['key' => 'kunde', 'value' => '4711', 'color' => '#6366f1']
            && $r->idempotencyKey() !== null);
    });

    it('reports an existing tag on 200', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => tagPayload()], 200)]);

        $result = $client->tags()->ensure('kunde', '4711');

        expect($result->created)->toBeFalse()
            ->and($result->existed())->toBeTrue()
            ->and($http->lastRequest()?->json())->toBe(['key' => 'kunde']  + ['value' => '4711']);
    });

    it('omits value and color when not given', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => tagPayload(['value' => null, 'label' => 'critical'])], 201)]);

        $client->tags()->ensure('critical');

        expect($http->lastRequest()?->json())->toBe(['key' => 'critical']);
    });

    it('surfaces the tag cap for a new tag as a validation error on tags', function (): void {
        $message = 'Your organization has reached the maximum of 50 tags.';
        [$client] = singleShotClient([MockResponse::error($message, 422, ['tags' => [$message]])]);

        try {
            $client->tags()->ensure('kunde', '4712');
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->firstError('tags'))->toBe($message);
        }
    });

    it('refuses a blank key before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): EnsuredTag => $client->tags()->ensure(''))->toThrow(InvalidArgumentException::class, 'blank');
        $http->assertNothingSent();
    });
});

describe('idempotency key', function (): void {
    it('sends the given key instead of a generated one', function (Closure $call): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => tagPayload()], 201)]);

        $call($client->tags(), 'order-4711-tag');

        expect($http->lastRequest()?->idempotencyKey())->toBe('order-4711-tag');
    })->with('creating tag calls');

    it('generates a UUID v4 key when none is given', function (Closure $call): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => tagPayload()], 201)]);

        $call($client->tags(), null);

        expect(Uuid::isV4((string) $http->lastRequest()?->idempotencyKey()))->toBeTrue();
    })->with('creating tag calls');

    it('refuses a malformed key before anything is sent', function (Closure $call): void {
        [$client, $http] = fakeClient();

        expect(fn(): mixed => $call($client->tags(), 'has space'))->toThrow(InvalidArgumentException::class, 'visible ASCII');
        $http->assertNothingSent();
    })->with('creating tag calls');
});

dataset('creating tag calls', [
    'ensure' => [fn(TagsInterface $tags, ?string $key): mixed => $tags->ensure('kunde', '4711', idempotencyKey: $key)],
    'create' => [fn(TagsInterface $tags, ?string $key): mixed => $tags->create('kunde', '4711', idempotencyKey: $key)],
]);

describe('create', function (): void {
    it('creates strictly and returns the tag', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => tagPayload()], 201)]);

        $tag = $client->tags()->create('kunde', '4711');

        expect($tag->id)->toBe('V1StGXR8Z5jdHi6BmyT01');
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/tags') && $r->json() === ['key' => 'kunde', 'value' => '4711']);
    });

    it('turns a duplicate into a validation error on key', function (): void {
        [$client] = singleShotClient([MockResponse::error(
            'A tag with this key/value already exists — import it instead of re-creating it.',
            422,
            ['key' => ['A tag with this key/value already exists — import it instead of re-creating it.']],
        )]);

        try {
            $client->tags()->create('kunde', '4711');
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->firstError('key'))->toContain('already exists');
        }
    });
});

describe('update', function (): void {
    it('patches only what was set', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => tagPayload(['color' => '#22c55e'])])]);

        $tag = $client->tags()->update('V1StGXR8Z5jdHi6BmyT01', UpdateTag::make()->withColor('#22c55e'));

        expect($tag->color)->toBe('#22c55e');
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('PATCH', '/v1/tags/V1StGXR8Z5jdHi6BmyT01')
            && $r->json() === ['color' => '#22c55e']
            && ! $r->hasHeader('Idempotency-Key'));
    });

    it('sends an explicit null to drop the value', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => tagPayload(['value' => null, 'label' => 'kunde'])])]);

        $client->tags()->update('V1StGXR8Z5jdHi6BmyT01', UpdateTag::make()->withKey('kunde')->withoutValue());

        expect($http->lastRequest()?->body)->toBe('{"key":"kunde","value":null}');
    });

    it('refuses an empty update before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): Tag => $client->tags()->update('abc', UpdateTag::make()))->toThrow(InvalidArgumentException::class, 'no changes');
        $http->assertNothingSent();
    });
});

describe('delete', function (): void {
    it('deletes and returns nothing', function (): void {
        [$client, $http] = fakeClient([MockResponse::noContent()]);

        $client->tags()->delete('V1StGXR8Z5jdHi6BmyT01');

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('DELETE', '/v1/tags/V1StGXR8Z5jdHi6BmyT01') && $r->body === '');

        expect($http->recorded())->toHaveCount(1)
            ->and($http->lastRequest()?->hasHeader('Idempotency-Key'))->toBeFalse();
    });

    it('surfaces a reference-protected tag as a validation error', function (): void {
        [$client] = singleShotClient([MockResponse::error('The tag is still referenced by an SLA objective.', 422)]);

        expect(fn() => $client->tags()->delete('abc'))->toThrow(ValidationException::class);
    });
});
