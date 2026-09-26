<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Data\EnsuredTag;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Payloads\UpdateTag;
use LIVCK\Cloud\Query\TagQuery;

/**
 * Tags: org-wide labels for services (`customer:4711`, `env:prod`, `critical`).
 *
 * Abilities: reads need `services.view`, writes `services.edit`, deletion
 * `services.delete`. The plan caps the number of tags; a tag that would exceed the cap
 * is a {@see ValidationException} on `tags`, not a plan-limit exception. The keys `os`,
 * `distro`, `kernel`, `arch`, `virt`, `agent` and `host` belong to the server agent and
 * are rejected.
 */
interface TagsInterface
{
    /**
     * One page of tags, optionally filtered by label or key.
     *
     * @return Page<Tag>
     *
     * @throws ApiException
     */
    public function list(?TagQuery $query = null): Page;

    /**
     * Every matching tag across all pages, one request per page as the iteration advances.
     *
     * @return Generator<int, Tag>
     *
     * @throws ApiException
     */
    public function each(?TagQuery $query = null): Generator;

    /**
     * @throws NotFoundException
     * @throws ApiException
     */
    public function get(string $id): Tag;

    /**
     * The tag with exactly this label (`customer:4711`, or a bare `critical` for the tag
     * without a value), or null. Keys match case-insensitively, values exactly.
     *
     * @throws ApiException
     */
    public function findByLabel(string $label): ?Tag;

    /**
     * Find or create: the tag exists afterwards either way. `created` tells whether this
     * call made it. An existing tag is returned unchanged; `color` applies to a new one
     * only. Safe to call concurrently: every caller gets the same tag.
     *
     * `$idempotencyKey` replaces the generated `Idempotency-Key` for this call: a stable
     * key (e.g. an order number) makes the create safe to repeat after a crash, for 24
     * hours, per token.
     *
     * @throws InvalidArgumentException for a blank key or a malformed idempotency key (nothing is sent)
     * @throws ValidationException for a malformed key, value or color, or a reserved key; on
     *                             `tags` when a NEW tag would exceed the plan's tag cap
     * @throws ApiException
     */
    public function ensure(string $key, ?string $value = null, ?string $color = null, ?string $idempotencyKey = null): EnsuredTag;

    /**
     * Strict create: an existing key/value pair is a {@see ValidationException} on `key`
     * (so that automation never adopts a tag it did not create). Use {@see ensure()} when
     * "already exists" is fine. Without `color` the server derives one from key and value.
     *
     * `$idempotencyKey` replaces the generated `Idempotency-Key` for this call: a stable
     * key (e.g. an order number) makes the create safe to repeat after a crash, for 24
     * hours, per token.
     *
     * @throws InvalidArgumentException for a blank key or a malformed idempotency key (nothing is sent)
     * @throws ValidationException on `key` for a duplicate or a reserved key; on `tags` when
     *                             the tag would exceed the plan's tag cap
     * @throws ApiException
     */
    public function create(string $key, ?string $value = null, ?string $color = null, ?string $idempotencyKey = null): Tag;

    /**
     * Rename and/or recolor; the id stays, every tagged service follows.
     *
     * @throws NotFoundException
     * @throws ValidationException when the new key/value pair exists, or a system tag is renamed
     * @throws ApiException
     */
    public function update(string $id, UpdateTag $changes): Tag;

    /**
     * Delete a tag. Refused with a {@see ValidationException} while SLA objectives or
     * tag-synced statuspage groups still reference it.
     *
     * @throws NotFoundException
     * @throws ValidationException
     * @throws ApiException
     */
    public function delete(string $id): void;
}
