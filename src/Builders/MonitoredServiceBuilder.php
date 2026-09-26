<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\JsonObject;
use Override;

/**
 * What every check that probes run has in common: the schedule (interval, timeout,
 * retries) and the monitoring locations with their roles. A `manual` service has none of
 * it, so these setters do not exist there.
 */
abstract class MonitoredServiceBuilder extends ServiceBuilder
{
    public const int DEFAULT_INTERVAL_SECONDS = 60;

    protected ?int $intervalSeconds = null;

    protected ?int $timeoutSeconds = null;

    protected ?int $retries = null;

    /** @var list<string>|null */
    protected ?array $probes = null;

    /** @var array<string, ProbeRole> */
    protected array $probeRoles = [];

    /**
     * Seconds between two checks. The plan's minimum and the type's own range apply on
     * the server (422 on `settings.interval_seconds`); SSL checks run hourly at most.
     */
    public function interval(int $seconds): static
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('The check interval must be at least one second.');
        }

        $copy = clone $this;
        $copy->intervalSeconds = $seconds;

        return $copy;
    }

    /** Seconds a single check may take (1 to 60). */
    public function timeout(int $seconds): static
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('The check timeout must be at least one second.');
        }

        $copy = clone $this;
        $copy->timeoutSeconds = $seconds;

        return $copy;
    }

    /** Failed attempts before a check counts as failed (0 to 5). */
    public function retries(int $retries): static
    {
        if ($retries < 0) {
            throw new InvalidArgumentException('Retries must not be negative.');
        }

        $copy = clone $this;
        $copy->retries = $retries;

        return $copy;
    }

    /**
     * The monitoring locations, by code (`GET /v1/probes`). Omitted, the organization's
     * default locations apply. The plan caps how many a service may have.
     */
    public function probes(string ...$codes): static
    {
        if ($codes === []) {
            throw new InvalidArgumentException('probes() needs at least one location code; leave it out to inherit the organization\'s locations.');
        }

        foreach ($codes as $code) {
            if (trim($code) === '') {
                throw new InvalidArgumentException('A location code must not be blank.');
            }
        }

        $copy = clone $this;
        $copy->probes = array_values(array_unique($codes));

        return $copy;
    }

    /**
     * The role of one location. A `reachability` location still checks and still alerts
     * but is left out of the uptime and response-time figures; at least one location must
     * stay `full`, and the location must be one the service monitors.
     */
    public function probeRole(string $code, ProbeRole $role): static
    {
        if (trim($code) === '') {
            throw new InvalidArgumentException('A location code must not be blank.');
        }

        $copy = clone $this;
        $copy->probeRoles[$code] = $role;

        return $copy;
    }

    #[Override]
    protected function defaultIntervalSeconds(): ?int
    {
        return self::DEFAULT_INTERVAL_SECONDS;
    }

    #[Override]
    protected function typedSettings(): array
    {
        $settings = ['interval_seconds' => $this->intervalSeconds ?? $this->defaultIntervalSeconds() ?? self::FALLBACK_INTERVAL_SECONDS];

        if ($this->timeoutSeconds !== null) {
            $settings['timeout_seconds'] = $this->timeoutSeconds;
        }

        if ($this->retries !== null) {
            $settings['retries'] = $this->retries;
        }

        if ($this->probes !== null) {
            $settings['assigned_probes'] = $this->probes;
        }

        if ($this->probeRoles !== []) {
            $settings['probe_roles'] = JsonObject::of(array_map(self::wireValue(...), $this->probeRoles));
        }

        return $settings;
    }
}
