<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Data\Statuspage;
use LIVCK\Cloud\Enums\AssetType;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Payloads\AssetFile;
use LIVCK\Cloud\Payloads\CreateStatuspage;
use LIVCK\Cloud\Payloads\UpdateStatuspage;
use LIVCK\Cloud\Query\StatuspageQuery;

/**
 * Status pages, and through {@see components()} and {@see customDomains()} what is on them
 * and where they are served.
 *
 * Abilities: reads need `statuspages.view`; `create` needs `statuspages.create`, `delete`
 * `statuspages.delete`, `publish` and `unpublish` `statuspages.publish`; `update` and the
 * asset endpoints `statuspages.edit`.
 *
 * The plan caps the number of published pages and, higher, the number of pages in total.
 * Both surface as a {@see ValidationException}, not as a plan-limit exception: on `name`
 * when `create` would exceed the total, on `is_published` when `publish` would exceed the
 * published ones.
 */
interface StatuspagesInterface
{
    /**
     * One page of status pages, newest first, optionally the one with a given slug. Entries
     * carry `componentsCount` but not the components themselves.
     *
     * @return Page<Statuspage>
     *
     * @throws ApiException
     */
    public function list(?StatuspageQuery $query = null): Page;

    /**
     * Every status page across all pages of the list, one request per page as the iteration
     * advances.
     *
     * @return Generator<int, Statuspage>
     *
     * @throws ApiException
     */
    public function each(?StatuspageQuery $query = null): Generator;

    /**
     * A status page with its components.
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function get(string $id): Statuspage;

    /**
     * The status page with exactly this slug (the part before `.statuspage.de`), or null.
     * Slugs are unique across all organizations; one of another organization reads as null.
     * The page comes without its components, like a list entry.
     *
     * @throws InvalidArgumentException for a blank slug (nothing is sent)
     * @throws ApiException
     */
    public function findBySlug(string $slug): ?Statuspage;

    /**
     * Create a status page. It starts public and goes online right away when the plan has a
     * free published slot; otherwise it is created unpublished (`isPublished` false), to be
     * published once a slot is free.
     *
     * `$idempotencyKey` replaces the generated `Idempotency-Key` for this call: a stable
     * key (e.g. an order number) makes the create safe to repeat after a crash, for 24
     * hours, per token.
     *
     * @throws InvalidArgumentException for a malformed idempotency key (nothing is sent)
     * @throws ValidationException on `slug` when it is malformed, taken or reserved; on `name`
     *                             when the organization has as many pages as the plan allows
     * @throws ApiException
     */
    public function create(CreateStatuspage $page, ?string $idempotencyKey = null): Statuspage;

    /**
     * Change what was set on the payload.
     *
     * @throws InvalidArgumentException when the payload carries no changes (nothing is sent)
     * @throws NotFoundException
     * @throws ValidationException for a malformed or taken slug, a malformed color or link, a
     *                             password access without a password, an email whitelist
     *                             access without addresses, or languages the organization
     *                             does not offer
     * @throws ApiException
     */
    public function update(string $id, UpdateStatuspage $changes): Statuspage;

    /**
     * Delete a status page with its components, metrics and subscribers; its custom domains
     * are released. The slug stays reserved against other organizations for a while.
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function delete(string $id): void;

    /**
     * Put the page online. Publishing a published page changes nothing.
     *
     * @throws NotFoundException
     * @throws ValidationException on `is_published` when the plan's published pages are all taken
     * @throws ApiException
     */
    public function publish(string $id): Statuspage;

    /**
     * Take the page offline: its address answers with 404 until it is published again.
     * Always allowed; unpublishing an unpublished page changes nothing.
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function unpublish(string $id): Statuspage;

    /**
     * Upload (or replace) the logo, the dark logo or the favicon, as a multipart POST. The
     * returned page carries the served address in `logoUrl`, `logoDarkUrl` or `faviconUrl`;
     * like every asset response it comes without components.
     *
     * Logos take JPEG, PNG, WebP or SVG up to 2 MB, the favicon ICO, PNG or SVG up to 1 MB;
     * the server checks the content, sanitizes SVGs and refuses raster images it cannot read.
     * Replacing is harmless to repeat, so a network error is retried like any other.
     *
     * @throws InvalidArgumentException for {@see AssetType::Unrecognized} (nothing is sent)
     * @throws NotFoundException
     * @throws ValidationException on `file` for a wrong type, an oversized or unreadable file
     * @throws ApiException
     */
    public function uploadAsset(string $id, AssetType $type, AssetFile $file): Statuspage;

    /**
     * Remove the logo, the dark logo or the favicon. Removing a missing one changes nothing.
     * The returned page comes without components.
     *
     * @throws InvalidArgumentException for {@see AssetType::Unrecognized} (nothing is sent)
     * @throws NotFoundException
     * @throws ApiException
     */
    public function deleteAsset(string $id, AssetType $type): Statuspage;

    /**
     * The components of one status page. Nothing is sent until a method is called; the
     * object is cheap, so ask for it where needed rather than keeping one per page.
     *
     * @throws InvalidArgumentException for a blank id
     */
    public function components(string $statuspageId): StatuspageComponentsInterface;

    /**
     * The custom domains of one status page. Nothing is sent until a method is called; the
     * object is cheap, so ask for it where needed rather than keeping one per page.
     *
     * @throws InvalidArgumentException for a blank id
     */
    public function customDomains(string $statuspageId): CustomDomainsInterface;
}
