<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Payloads;

use LIVCK\Cloud\Data\StatuspageComponent;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\TagIds;
use LIVCK\Cloud\Support\Translatable;

/**
 * A partial update for `PATCH /v1/statuspages/{id}/components/{component}`: only what was set
 * is sent, everything else stays as it is.
 *
 *     UpdateComponent::make()->withName('Website')->withIsVisible(false)
 *
 * Components a synced group added (`isSyncManaged`) keep their service and parent: changing
 * either is refused with a {@see \LIVCK\Cloud\Exceptions\ValidationException} on
 * `service_id` or `parent_id`. Name, description, visibility, order and display options stay
 * editable, and survive later syncs.
 */
final readonly class UpdateComponent
{
    /**
     * @param array<string, mixed> $changes
     */
    private function __construct(
        private array $changes = [],
    ) {}

    public static function make(): self
    {
        return new self();
    }

    /**
     * A plain string replaces every stored translation with one text in the organization's
     * default language; pass a {@see Translatable} with all languages to keep them.
     */
    public function withName(Translatable|string $name): self
    {
        return $this->with('name', Translatable::from($name)->jsonSerialize());
    }

    /**
     * Up to 500 characters per language; null removes the description. A plain string
     * replaces every stored translation.
     */
    public function withDescription(Translatable|string|null $description): self
    {
        return $this->with('description', $description === null ? null : Translatable::from($description)->jsonSerialize());
    }

    public function withoutDescription(): self
    {
        return $this->withDescription(null);
    }

    /** Link a monitored service of the organization; its checks then drive the status. */
    public function withService(string $serviceId): self
    {
        return $this->with('service_id', $this->id($serviceId, 'service'));
    }

    /** Unlink the service: the component becomes a manual one. */
    public function withoutService(): self
    {
        return $this->with('service_id', null);
    }

    /**
     * Move the component into a group of the same page; its children move along. The new
     * place must not lie inside the component itself and must leave the moved subtree within
     * the nesting limit.
     */
    public function withParent(StatuspageComponent|string $parent): self
    {
        return $this->with('parent_id', $this->id($parent instanceof StatuspageComponent ? $parent->id : $parent, 'parent'));
    }

    /** Move the component to the top level. */
    public function withoutParent(): self
    {
        return $this->with('parent_id', null);
    }

    /**
     * Turn the component into a group, or a group back into a component. A synced group
     * stays a group until its sync is removed ({@see withoutSyncTag()}, in the same update
     * or before).
     */
    public function withIsGroup(bool $isGroup): self
    {
        return $this->with('is_group', $isGroup);
    }

    public function withIsVisible(bool $visible): self
    {
        return $this->with('is_visible', $visible);
    }

    /** Position among the children of the same parent, 0 to 10,000, ascending. */
    public function withDisplayOrder(int $order): self
    {
        return $this->with('display_order', $order);
    }

    public function withShowUptimeBars(bool $show): self
    {
        return $this->with('show_uptime_bars', $show);
    }

    /** Groups: leave operational children off the page, so a healthy group is a single row. */
    public function withHideOperationalChildren(bool $hide): self
    {
        return $this->with('hide_operational_children', $hide);
    }

    /** Groups: expanded on a visitor's first visit. A group with an outage always opens. */
    public function withDefaultOpen(bool $open): self
    {
        return $this->with('default_open', $open);
    }

    /**
     * Make a group sync with a tag, or switch it to another tag. The group's children are
     * brought in line before the response returns: services with the tag are added, children
     * the previous tag had added and the new one does not cover are deleted, and children you
     * added by hand whose service carries the tag are taken over.
     *
     * The group names one EXISTING tag by id (`sync_tag_id`): a name such as `customer:4711` is
     * refused before anything is sent. `tags()->ensure()` hands you the tag, created or found.
     *
     * @param Tag|string $tag the tag or its id, never its name
     */
    public function withSyncTag(Tag|string $tag): self
    {
        return $this->with('sync_tag_id', TagIds::of($tag)[0]);
    }

    /**
     * Stop syncing. The children stay exactly as they are and become ordinary components,
     * editable and deletable like any other.
     */
    public function withoutSyncTag(): self
    {
        return $this->with('sync_tag_id', null);
    }

    /** Synced groups: whether children the sync adds from now on are visible right away. */
    public function withSyncNewVisible(bool $visible): self
    {
        return $this->with('sync_new_visible', $visible);
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->changes;
    }

    private function id(string $id, string $what): string
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException(sprintf('A %s id must not be blank.', $what));
        }

        return $id;
    }

    private function with(string $field, mixed $value): self
    {
        return new self([...$this->changes, $field => $value]);
    }
}
