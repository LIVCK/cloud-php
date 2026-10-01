<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Enums\IpFamily;
use LIVCK\Cloud\Support\Field;

/**
 * An address LIVCK saw a server agent call from.
 */
final readonly class ObservedIp
{
    /**
     * @param DateTimeImmutable|null $at when it was last seen
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $ip,
        public IpFamily $family,
        public ?DateTimeImmutable $at,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'ip'),
            IpFamily::fromApi(Field::string($data, 'family')),
            Field::nullableInstant($data, 'at'),
            $data,
        );
    }
}
