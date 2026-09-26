<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Data\Concerns\ReadsStringMaps;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\Field;

/**
 * A service's monitoring configuration (`settings` on `/v1/services`).
 *
 * `config` is the check-type specific block as stored, with every secret masked: header
 * values and `auth.token` / `auth.password` / `auth.value` read as
 * {@see \LIVCK\Cloud\Support\KeepSecret::SENTINEL}. Sending them back unchanged keeps the
 * stored secrets ({@see \LIVCK\Cloud\Payloads\UpdateService::basedOn()}).
 */
final readonly class ServiceSettings
{
    use ReadsStringMaps;

    /**
     * @param list<string>|null $assignedProbes location codes; null when the organization's default locations apply
     * @param array<string, ProbeRole>|null $probeRoles role per location; null when the organization's roles apply (an absent location is `full`)
     * @param array<string, mixed> $config the check-type specific configuration, secrets masked
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public int $intervalSeconds,
        public int $timeoutSeconds,
        public int $retries,
        public ?array $assignedProbes,
        public ?array $probeRoles,
        public array $config,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::int($data, 'interval_seconds'),
            Field::int($data, 'timeout_seconds'),
            Field::int($data, 'retries'),
            ($data['assigned_probes'] ?? null) === null ? null : Field::stringList($data, 'assigned_probes'),
            self::probeRoles($data),
            Field::object($data, 'config'),
            $data,
        );
    }

    /** Whether the organization's default locations apply. */
    public function inheritsProbes(): bool
    {
        return $this->assignedProbes === null;
    }

    /** A config value by key (`method`, `dns_type`, …), or the default when absent. */
    public function value(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * The stored conditions, empty when the config carries none.
     *
     * @return list<ConditionRule>
     */
    public function conditions(): array
    {
        return array_map(ConditionRule::fromArray(...), Field::objectList($this->config, 'conditions'));
    }

    /**
     * The HTTP headers of an HTTP check; every value is the keep sentinel.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return self::nullableStringMap($this->config, 'headers') ?? [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, ProbeRole>|null
     */
    private static function probeRoles(array $data): ?array
    {
        $map = Field::nullableObject($data, 'probe_roles');

        if ($map === null) {
            return null;
        }

        $roles = [];

        foreach ($map as $code => $role) {
            if (! is_string($role)) {
                throw new UnexpectedResponseException(sprintf('Field "probe_roles.%s": expected string, got %s.', $code, get_debug_type($role)));
            }

            $roles[$code] = ProbeRole::fromApi($role);
        }

        return $roles;
    }
}
