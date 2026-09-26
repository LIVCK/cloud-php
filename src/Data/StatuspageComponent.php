<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Data\Concerns\ReadsStringMaps;
use LIVCK\Cloud\Enums\ComponentStatus;
use LIVCK\Cloud\Support\Field;

/**
 * A row on a status page: a group, a component linked to a monitored service, or a manual
 * component whose status comes from incidents and maintenances only.
 *
 * Components arrive as a flat list in display order; `parentId` points at the enclosing
 * group, null for the top level. A group with a `syncTagId` fills itself: it holds one child
 * per service carrying that tag (agent and push services excepted) and adds or removes
 * children as services gain or lose the tag. Those children are `isSyncManaged`.
 *
 * `name` and `description` are resolved to one language, the one the client asked for
 * (`ClientOptions::$locale`) or the organization's default; the `*Translations` maps hold
 * every stored language.
 */
final readonly class StatuspageComponent
{
    use ReadsStringMaps;

    /**
     * @param array<string, string>|null $nameTranslations the name per locale
     * @param array<string, string>|null $descriptionTranslations the description per locale; null without a description
     * @param ComponentStatus $status the stored status. The public page does not read it, so it may differ from
     *                                what visitors see; it is kept for compatibility. Use `$effectiveStatus`.
     * @param ComponentStatus|null $effectiveStatus what the public page shows: derived from an active maintenance,
     *                                              an open published incident or the linked service, and for a group
     *                                              from its visible children. Null when the component is hidden or
     *                                              sits inside a hidden group.
     * @param int $displayOrder position among the children of the same parent, ascending
     * @param bool $hideOperationalChildren group option: operational children are left off the page
     * @param bool $defaultOpen group option: expanded on a visitor's first visit (a group with an outage always opens)
     * @param string|null $parentId the enclosing group, null on the top level
     * @param string|null $syncTagId groups only: the tag whose services this group holds
     * @param bool $syncNewVisible synced groups only: whether children the sync adds are visible right away
     * @param bool $isSyncManaged added by a synced group: service and parent are locked and the component cannot be
     *                            deleted; remove the tag from the service instead. Name, description, visibility and
     *                            order stay editable.
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?array $nameTranslations,
        public ?string $description,
        public ?array $descriptionTranslations,
        public ComponentStatus $status,
        public ?ComponentStatus $effectiveStatus,
        public bool $isGroup,
        public bool $isVisible,
        public int $displayOrder,
        public bool $showUptimeBars,
        public bool $hideOperationalChildren,
        public bool $defaultOpen,
        public ?string $parentId,
        public ?string $syncTagId,
        public bool $syncNewVisible,
        public bool $isSyncManaged,
        public ?Reference $service,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $effectiveStatus = Field::nullableString($data, 'effective_status');
        $service = Field::nullableObject($data, 'service');

        return new self(
            Field::string($data, 'id'),
            Field::string($data, 'name'),
            self::nullableStringMap($data, 'name_translations'),
            Field::nullableString($data, 'description'),
            self::nullableStringMap($data, 'description_translations'),
            ComponentStatus::fromApi(Field::string($data, 'status')),
            $effectiveStatus === null ? null : ComponentStatus::fromApi($effectiveStatus),
            Field::bool($data, 'is_group'),
            Field::bool($data, 'is_visible'),
            Field::int($data, 'display_order'),
            Field::bool($data, 'show_uptime_bars'),
            Field::bool($data, 'hide_operational_children'),
            Field::bool($data, 'default_open'),
            Field::nullableString($data, 'parent_id'),
            Field::nullableString($data, 'sync_tag_id'),
            Field::bool($data, 'sync_new_visible'),
            Field::bool($data, 'is_sync_managed'),
            $service === null ? null : Reference::fromArray($service),
            $data,
        );
    }

    /** A group that fills itself with the services carrying its tag. */
    public function isSyncedGroup(): bool
    {
        return $this->isGroup && $this->syncTagId !== null;
    }
}
