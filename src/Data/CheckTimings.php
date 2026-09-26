<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * Milliseconds per phase of one check. A phase is null where the check type has none (a
 * TCP check has no TLS, an ICMP check none at all) or it was not measured.
 */
final readonly class CheckTimings
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?float $dns,
        public ?float $connect,
        public ?float $tls,
        public ?float $ttfb,
        public ?float $transfer,
        public ?float $total,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::nullableFloat($data, 'dns'),
            Field::nullableFloat($data, 'connect'),
            Field::nullableFloat($data, 'tls'),
            Field::nullableFloat($data, 'ttfb'),
            Field::nullableFloat($data, 'transfer'),
            Field::nullableFloat($data, 'total'),
            $data,
        );
    }

    /** Whether any phase was measured. */
    public function isMeasured(): bool
    {
        return $this->dns !== null || $this->connect !== null || $this->tls !== null
            || $this->ttfb !== null || $this->transfer !== null || $this->total !== null;
    }
}
