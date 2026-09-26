<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\StatuspageComponent;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\TagIds;
use LIVCK\Cloud\Support\Translatable;

/**
 * A new statuspage component for `POST /v1/statuspages/{id}/components`. Pick the kind, then
 * add options:
 *
 *     ComponentBuilder::syncedGroup('Your services', $customerTag)   // fills itself from a tag
 *     ComponentBuilder::group('Infrastructure')
 *     ComponentBuilder::service($service)->parent($group)
 *     ComponentBuilder::manual('Phone support')->description('Hotline and callbacks')
 *
 * Like every builder, it is immutable: each option returns a new builder. Without
 * {@see parent()} the component sits on the top level; without {@see displayOrder()} it
 * goes after the last child of its parent. Unset options take the server's defaults:
 * visible, no uptime bars, groups expanded.
 */
final readonly class ComponentBuilder
{
    /**
     * @param array<string, mixed> $attributes
     */
    private function __construct(
        private array $attributes,
    ) {}

    /** A group: a heading that holds other components and shows the worst status among them. */
    public static function group(Translatable|string $name): self
    {
        return new self(['name' => self::text($name), 'is_group' => true]);
    }

    /**
     * A group that fills itself: it gets one child per service carrying the tag, and the
     * children follow the tag from then on (tag a service and it appears moments later,
     * untag it and it goes). Agent and push services are never added. The children are
     * {@see StatuspageComponent::$isSyncManaged}: their service and parent are locked and they
     * cannot be deleted one by one, while name, description, visibility and order stay
     * editable. Children appear right away, sorted by service name;
     * {@see syncNewVisible()} decides whether they are visible.
     *
     * A reseller with one tag per end customer (`customer:4711`) gets a page that keeps itself
     * up to date. The tag must be one you created (the server agent's system tags are
     * refused), and a synced group needs a spare nesting level below it.
     *
     * The group names one EXISTING tag by id (`sync_tag_id`), so unlike
     * {@see ServiceBuilder::tags()} this takes no names: a name such as `customer:4711` is
     * refused before anything is sent. `tags()->ensure()` hands you the tag, created or found.
     *
     * @param Tag|string $tag the tag or its id, never its name
     */
    public static function syncedGroup(Translatable|string $name, Tag|string $tag): self
    {
        return new self([
            'name' => self::text($name),
            'is_group' => true,
            'sync_tag_id' => TagIds::of($tag)[0],
        ]);
    }

    /**
     * A component whose status follows a monitored service (and the incidents and
     * maintenances that affect it).
     *
     * @param Service|string $service the service or its id; it must belong to the organization
     * @param Translatable|string|null $name shown on the page; defaults to the service's name
     *                                       when a {@see Service} is passed
     */
    public static function service(Service|string $service, Translatable|string|null $name = null): self
    {
        if ($name === null) {
            if (! $service instanceof Service) {
                throw new InvalidArgumentException('Pass a component name, or the Service itself so its name can be used.');
            }

            $name = $service->name;
        }

        $serviceId = $service instanceof Service ? $service->id : $service;

        return new self(['name' => self::text($name), 'service_id' => self::id($serviceId, 'service')]);
    }

    /**
     * A component without a monitored service: operational unless an incident or a
     * maintenance says otherwise.
     */
    public static function manual(Translatable|string $name): self
    {
        return new self(['name' => self::text($name)]);
    }

    /**
     * The group to place the component in; a group of the same page. Groups nest a limited
     * number of levels deep.
     */
    public function parent(StatuspageComponent|string $parent): self
    {
        return $this->with('parent_id', self::id($parent instanceof StatuspageComponent ? $parent->id : $parent, 'parent'));
    }

    /** Up to 500 characters per language. */
    public function description(Translatable|string $description): self
    {
        return $this->with('description', self::text($description));
    }

    public function visible(bool $visible = true): self
    {
        return $this->with('is_visible', $visible);
    }

    /** Position among the children of the same parent, 0 to 10,000, ascending. */
    public function displayOrder(int $order): self
    {
        return $this->with('display_order', $order);
    }

    /**
     * Show the uptime history bars. On a synced group, children the sync adds inherit the
     * setting.
     */
    public function showUptimeBars(bool $show = true): self
    {
        return $this->with('show_uptime_bars', $show);
    }

    /** Groups: leave operational children off the page, so a healthy group is a single row. */
    public function hideOperationalChildren(bool $hide = true): self
    {
        return $this->with('hide_operational_children', $hide);
    }

    /** Groups: expanded on a visitor's first visit. A group with an outage always opens. */
    public function defaultOpen(bool $open = true): self
    {
        return $this->with('default_open', $open);
    }

    /**
     * Synced groups: whether children the sync adds are visible right away (the default), or
     * hidden until you show them.
     */
    public function syncNewVisible(bool $visible = true): self
    {
        return $this->with('sync_new_visible', $visible);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * @return string|array<string, string>
     */
    private static function text(Translatable|string $text): string|array
    {
        return Translatable::from($text)->jsonSerialize();
    }

    private static function id(string $id, string $what): string
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException(sprintf('A %s id must not be blank.', $what));
        }

        return $id;
    }

    private function with(string $field, mixed $value): self
    {
        return new self([...$this->attributes, $field => $value]);
    }
}
