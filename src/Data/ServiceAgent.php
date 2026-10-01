<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Enums\AgentState;
use LIVCK\Cloud\Support\Field;

/**
 * The server behind an `agent` service ({@see Service::$agent}): its state, the facts the agent
 * reports about the host, and its update settings. The figures are read separately
 * (`services()->agentMetrics()`).
 */
final readonly class ServiceAgent
{
    /**
     * @param DateTimeImmutable|null $stateChangedAt when the state last changed
     * @param DateTimeImmutable|null $lastSeenAt null before the first report
     * @param string|null $version the agent's version
     * @param string|null $os host facts as the agent reports them; null until it has
     * @param int|null $ramTotalBytes memory in bytes
     * @param DateTimeImmutable|null $bootedAt when the host last booted
     * @param bool $rebootRequired the operating system asks for a reboot (pending updates)
     * @param DateTimeImmutable|null $instanceConflictAt set when a second host reported with this agent's identity
     *                                                   (a cloned machine)
     * @param string|null $enrollmentKeyId the enrollment key the server enrolled with; null for servers enrolled before
     *                                     keys were recorded, and once the key has been deleted
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public AgentState $state,
        public ?DateTimeImmutable $stateChangedAt,
        public ?DateTimeImmutable $lastSeenAt,
        public string $hostname,
        public ?string $version,
        public ?string $os,
        public ?string $distro,
        public ?string $distroVersion,
        public ?string $kernel,
        public ?string $arch,
        public ?string $virtualization,
        public ?string $cpuModel,
        public ?int $cpuCores,
        public ?int $ramTotalBytes,
        public ?DateTimeImmutable $bootedAt,
        public bool $rebootRequired,
        public AgentIps $ips,
        public AgentUpdateSettings $update,
        public ?DateTimeImmutable $instanceConflictAt,
        public ?string $enrollmentKeyId,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            AgentState::fromApi(Field::string($data, 'state')),
            Field::nullableInstant($data, 'state_changed_at'),
            Field::nullableInstant($data, 'last_seen_at'),
            Field::string($data, 'hostname'),
            Field::nullableString($data, 'version'),
            Field::nullableString($data, 'os'),
            Field::nullableString($data, 'distro'),
            Field::nullableString($data, 'distro_version'),
            Field::nullableString($data, 'kernel'),
            Field::nullableString($data, 'arch'),
            Field::nullableString($data, 'virtualization'),
            Field::nullableString($data, 'cpu_model'),
            Field::nullableInt($data, 'cpu_cores'),
            Field::nullableInt($data, 'ram_total_bytes'),
            Field::nullableInstant($data, 'booted_at'),
            Field::bool($data, 'reboot_required'),
            AgentIps::fromArray(Field::object($data, 'ips')),
            AgentUpdateSettings::fromArray(Field::object($data, 'update')),
            Field::nullableInstant($data, 'instance_conflict_at'),
            Field::nullableString($data, 'enrollment_key_id'),
            $data,
        );
    }
}
