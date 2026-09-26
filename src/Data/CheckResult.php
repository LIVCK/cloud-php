<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Enums\CheckResultStatus;
use LIVCK\Cloud\Support\Field;

/**
 * One check a probe ran (`GET /v1/services/{id}/checks`). A check has no id of its own:
 * `checkedAt` and `probe` together identify it.
 */
final readonly class CheckResult
{
    /**
     * @param DateTimeImmutable $checkedAt when the check ran, UTC, millisecond precision
     * @param string $probe the location code that ran it (`GET /v1/probes`)
     * @param int|null $responseTimeMs how long the check took
     * @param int|null $statusCode the HTTP status the target answered with; null for other check types and when nothing answered
     * @param string|null $errorMessage a short readable reason when the check did not pass (`Connection refused`)
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public DateTimeImmutable $checkedAt,
        public string $probe,
        public CheckResultStatus $status,
        public ?int $responseTimeMs,
        public ?int $statusCode,
        public ?string $errorMessage,
        public CheckTimings $timings,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::instant($data, 'checked_at'),
            Field::string($data, 'probe'),
            CheckResultStatus::fromApi(Field::string($data, 'status')),
            Field::nullableInt($data, 'response_time_ms'),
            Field::nullableInt($data, 'status_code'),
            Field::nullableString($data, 'error_message'),
            CheckTimings::fromArray(Field::object($data, 'timings')),
            $data,
        );
    }

    public function passed(): bool
    {
        return $this->status === CheckResultStatus::Up;
    }
}
