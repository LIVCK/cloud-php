<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\PausedReason;
use LIVCK\Cloud\Enums\ServiceStatus;
use LIVCK\Cloud\Enums\StatusOverride;
use LIVCK\Cloud\Support\Field;

/**
 * A monitored service (`/v1/services`).
 *
 * `status` is what the checks measured; `effectiveStatus` is what everyone sees, which is
 * the manual override while one is set. A monitored service created without `settings`
 * exists but is not monitored (`isConfigured` false, `settings` null) until it gets some; a
 * `manual` service has nothing to configure and is configured from the start, with
 * `settings` null. An `agent` service is a server that enrolled with an enrollment key;
 * `agent` describes it.
 */
final readonly class Service
{
    /**
     * @param string|null $target null for a `manual` service
     * @param StatusOverride|null $statusOverride the pinned status while an override is set
     * @param string|null $statusOverrideReason shown to the team, never on a statuspage
     * @param float|null $uptime30d uptime over the last 30 days in percent (0–100); null without data
     * @param float|null $avgResponseMs average response time over the last 30 days; null without data
     * @param list<Tag>|null $tags the service's tags (every v1 service endpoint includes them)
     * @param DateTimeImmutable|null $lastCheckAt null before the first check
     * @param ServiceSettings|null $settings null while the service is not configured
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     * @param ServiceAgent|null $agent the server behind an `agent` service; null for every other check type. It
     *                                 follows `$raw` so that constructor calls written for earlier versions
     *                                 keep working.
     */
    public function __construct(
        public string $id,
        public string $name,
        public CheckType $checkType,
        public string $checkTypeLabel,
        public ?string $target,
        public ServiceStatus $status,
        public ServiceStatus $effectiveStatus,
        public ?StatusOverride $statusOverride,
        public ?string $statusOverrideReason,
        public ?DateTimeImmutable $statusOverrideAt,
        public ?string $faviconUrl,
        public bool $isPaused,
        public ?PausedReason $pausedReason,
        public ?DateTimeImmutable $configuredAt,
        public bool $isConfigured,
        public ?float $uptime30d,
        public ?float $avgResponseMs,
        public ?array $tags,
        public ?DateTimeImmutable $lastCheckAt,
        public DateTimeImmutable $createdAt,
        public ?ServiceSettings $settings,
        public array $raw,
        public ?ServiceAgent $agent = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $override = Field::nullableString($data, 'status_override');
        $pausedReason = Field::nullableString($data, 'paused_reason');
        $settings = Field::nullableObject($data, 'settings');
        $agent = Field::nullableObject($data, 'agent');

        return new self(
            Field::string($data, 'id'),
            Field::string($data, 'name'),
            CheckType::fromApi(Field::string($data, 'check_type')),
            Field::string($data, 'check_type_label'),
            Field::nullableString($data, 'target'),
            ServiceStatus::fromApi(Field::string($data, 'status')),
            ServiceStatus::fromApi(Field::string($data, 'effective_status')),
            $override === null ? null : StatusOverride::fromApi($override),
            Field::nullableString($data, 'status_override_reason'),
            Field::nullableInstant($data, 'status_override_at'),
            Field::nullableString($data, 'favicon_url'),
            Field::bool($data, 'is_paused'),
            $pausedReason === null ? null : PausedReason::fromApi($pausedReason),
            Field::nullableInstant($data, 'configured_at'),
            Field::bool($data, 'is_configured'),
            Field::nullableFloat($data, 'uptime_30d'),
            Field::nullableFloat($data, 'avg_response_ms'),
            array_key_exists('tags', $data) ? array_map(Tag::fromArray(...), Field::objectList($data, 'tags')) : null,
            Field::nullableInstant($data, 'last_check_at'),
            Field::instant($data, 'created_at'),
            $settings === null ? null : ServiceSettings::fromArray($settings),
            $data,
            $agent === null ? null : ServiceAgent::fromArray($agent),
        );
    }

    public function hasStatusOverride(): bool
    {
        return $this->statusOverride instanceof StatusOverride;
    }

    /** Whether probes run checks for this service (and a check history exists). */
    public function isMonitoredByProbes(): bool
    {
        return $this->checkType->isMonitoredByProbes();
    }

    /**
     * The ids of the service's tags, in the form `tags` and `UpdateService::withTags()` take.
     *
     * @return list<string>
     */
    public function tagIds(): array
    {
        return array_map(static fn(Tag $tag): string => $tag->id, $this->tags ?? []);
    }

    /** Whether the service carries a tag with exactly this label (`customer:4711`). */
    public function hasTag(string $label): bool
    {
        foreach ($this->tags ?? [] as $tag) {
            if ($tag->label === $label) {
                return true;
            }
        }

        return false;
    }
}
