<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use LIVCK\Cloud\Builders\ComponentBuilder;
use LIVCK\Cloud\Data\StatuspageComponent;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Payloads\UpdateComponent;

/**
 * The components of one status page, from
 * {@see StatuspagesInterface::components()}.
 *
 * Abilities: reads need `statuspages.view`, writes `statuspages.edit`. A component of another
 * page, even one of the same organization, reads as not found.
 */
interface StatuspageComponentsInterface
{
    /**
     * Every component of the page as one flat list in display order (not paginated). Rebuild
     * the tree through `parentId`.
     *
     * @return list<StatuspageComponent>
     *
     * @throws NotFoundException when the status page does not exist
     * @throws ApiException
     */
    public function all(): array;

    /**
     * @throws NotFoundException
     * @throws ApiException
     */
    public function get(string $id): StatuspageComponent;

    /**
     * Create a group, a synced group, a service component or a manual component. A synced
     * group is filled before the response returns, so {@see all()} lists its children right
     * away; the response itself is the group.
     *
     * `$idempotencyKey` replaces the generated `Idempotency-Key` for this call: a stable
     * key (e.g. an order number) makes the create safe to repeat after a crash, for 24
     * hours, per token.
     *
     * @throws InvalidArgumentException for a malformed idempotency key (nothing is sent)
     * @throws NotFoundException when the status page, or the parent on this page, does not exist
     * @throws ValidationException on `parent_id` when the parent is no group or the nesting gets
     *                             too deep; on `service_id` for a service outside the organization
     *                             or one the synced parent already holds; on `sync_tag_id` for an
     *                             unknown tag, a system tag, or a synced group without a spare
     *                             nesting level below it
     * @throws ApiException
     */
    public function create(ComponentBuilder $component, ?string $idempotencyKey = null): StatuspageComponent;

    /**
     * Change what was set on the payload. For a component a synced group added
     * (`isSyncManaged`), changing service or parent is refused; everything else is allowed.
     *
     * @throws InvalidArgumentException when the payload carries no changes (nothing is sent)
     * @throws NotFoundException for the component, or a new parent that is not on this page
     * @throws ValidationException on `service_id`/`parent_id` for a sync-managed component; on
     *                             `parent_id` for a move into a non-group, into the component's
     *                             own subtree or beyond the nesting limit; on `is_group` when a
     *                             synced group would stop being a group; on `sync_tag_id` as for
     *                             {@see create()}
     * @throws ApiException
     */
    public function update(string $id, UpdateComponent $changes): StatuspageComponent;

    /**
     * Delete a component; a group takes its children along. A component a synced group added
     * cannot be deleted by itself: remove the tag from its service, or remove the group's sync
     * first.
     *
     * @throws NotFoundException
     * @throws ValidationException on `component` for a sync-managed component
     * @throws ApiException
     */
    public function delete(string $id): void;
}
