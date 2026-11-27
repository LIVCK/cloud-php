<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Enums\HeartbeatNotCreatedReason;
use LIVCK\Cloud\Support\Field;

/**
 * The new heartbeats a declaration could not create. Not an error: the call answered 200 and the
 * heartbeats that existed were aligned all the same.
 *
 * New heartbeats are created together or not at all, so `keys` is either empty or every new key
 * of the declaration. `reason` says why ({@see HeartbeatNotCreatedReason}); `missing` is how many
 * places the plan is short (`service_limit`), `retryAfter` the seconds until the creation limit
 * has room again (`creation_rate`; null when the list is larger than one hour or day allows).
 * Declaring again once there is room creates them.
 */
final readonly class NotCreatedHeartbeats
{
    /**
     * @param list<string> $keys
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public array $keys,
        public ?HeartbeatNotCreatedReason $reason,
        public ?int $missing,
        public ?int $retryAfter,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data the `not_created` object
     */
    public static function fromArray(array $data): self
    {
        $reason = Field::nullableString($data, 'reason');

        return new self(
            Field::stringList($data, 'keys'),
            $reason === null ? null : HeartbeatNotCreatedReason::fromApi($reason),
            Field::nullableInt($data, 'missing'),
            Field::nullableInt($data, 'retry_after'),
            $data,
        );
    }

    /** Every heartbeat of the declaration exists. */
    public function isEmpty(): bool
    {
        return $this->keys === [];
    }
}
