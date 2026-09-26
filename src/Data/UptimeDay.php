<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Enums\UptimeDayStatus;
use LIVCK\Cloud\Support\Day;
use LIVCK\Cloud\Support\Field;

/**
 * One calendar day of `GET /v1/services/{id}/uptime`.
 *
 * The server writes `uptime_percent: 0` for a day without measurements and marks it
 * `no_data`; read {@see uptime()} rather than `uptimePercent` so such a day is null and
 * never mistaken for a full outage.
 */
final readonly class UptimeDay
{
    /**
     * @param float $uptimePercent the figure as reported (0–100); a placeholder 0 on a `no_data` day
     * @param int $incidents incidents that touched the service that day
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public Day $date,
        public UptimeDayStatus $status,
        public float $uptimePercent,
        public int $incidents,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::day($data, 'date'),
            UptimeDayStatus::fromApi(Field::string($data, 'status')),
            Field::float($data, 'uptime_percent'),
            Field::int($data, 'incidents'),
            $data,
        );
    }

    public function hasData(): bool
    {
        return $this->status !== UptimeDayStatus::NoData;
    }

    /** The day's availability in percent, or null when nothing was measured. */
    public function uptime(): ?float
    {
        return $this->hasData() ? $this->uptimePercent : null;
    }
}
