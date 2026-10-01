<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;

/**
 * The addresses of a server: what the host reported for itself at enrollment, and where LIVCK
 * sees the agent call from.
 */
final readonly class AgentIps
{
    /**
     * @param list<string> $private private addresses the host reported at enrollment
     * @param list<string> $public public addresses the host reported at enrollment
     * @param list<ObservedIp> $observed the address the agent calls from, per IP family; behind NAT that is the
     *                                   network's address, not the host's
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public array $private,
        public array $public,
        public array $observed,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::stringList($data, 'private'),
            Field::stringList($data, 'public'),
            array_map(ObservedIp::fromArray(...), Field::objectList($data, 'observed')),
            $data,
        );
    }
}
