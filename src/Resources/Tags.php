<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Data\EnsuredTag;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Transport;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Payloads\UpdateTag;
use LIVCK\Cloud\Query\TagQuery;
use LIVCK\Cloud\Support\Envelope;
use LIVCK\Cloud\Support\Path;

/**
 * `/v1/tags`. The reference implementation every other resource follows: thin methods,
 * one request each, hydration through {@see Envelope}, ids percent-encoded through
 * {@see Path}, nothing kept between calls.
 */
final readonly class Tags implements TagsInterface
{
    public function __construct(
        private Transport $transport,
    ) {}

    public function list(?TagQuery $query = null): Page
    {
        $query ??= TagQuery::make();
        $response = $this->transport->send(Request::get('tags', $query->toArray()));

        return Envelope::page(
            $response,
            Tag::fromArray(...),
            fn(int $page): Page => $this->list($query->withPage($page)),
        );
    }

    public function each(?TagQuery $query = null): Generator
    {
        return $this->list($query)->lazy();
    }

    public function get(string $id): Tag
    {
        $response = $this->transport->send(Request::get(Path::join('tags', $id)));

        return Envelope::item($response, Tag::fromArray(...));
    }

    public function findByLabel(string $label): ?Tag
    {
        if (trim($label) === '') {
            throw new InvalidArgumentException('A tag label must not be blank.');
        }

        return $this->list(TagQuery::make()->withLabel($label)->withPerPage(1))->first();
    }

    public function ensure(string $key, ?string $value = null, ?string $color = null, ?string $idempotencyKey = null): EnsuredTag
    {
        $response = $this->transport->send(Request::post('tags/ensure', $this->attributes($key, $value, $color), $idempotencyKey));

        return new EnsuredTag(Envelope::item($response, Tag::fromArray(...)), $response->status() === 201);
    }

    public function create(string $key, ?string $value = null, ?string $color = null, ?string $idempotencyKey = null): Tag
    {
        $response = $this->transport->send(Request::post('tags', $this->attributes($key, $value, $color), $idempotencyKey));

        return Envelope::item($response, Tag::fromArray(...));
    }

    public function update(string $id, UpdateTag $changes): Tag
    {
        if ($changes->isEmpty()) {
            throw new InvalidArgumentException('UpdateTag carries no changes; set a key, value or color first.');
        }

        $response = $this->transport->send(Request::patch(Path::join('tags', $id), $changes->toArray()));

        return Envelope::item($response, Tag::fromArray(...));
    }

    public function delete(string $id): void
    {
        $this->transport->send(Request::delete(Path::join('tags', $id)));
    }

    /**
     * @return array<string, string>
     */
    private function attributes(string $key, ?string $value, ?string $color): array
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A tag key must not be blank.');
        }

        $attributes = ['key' => $key];

        if ($value !== null) {
            $attributes['value'] = $value;
        }

        if ($color !== null) {
            $attributes['color'] = $color;
        }

        return $attributes;
    }
}
