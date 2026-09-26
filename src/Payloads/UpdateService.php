<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Payloads;

use BackedEnum;
use LIVCK\Cloud\Builders\Conditions\Condition;
use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\ServiceSettings;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\ApiEnum;
use LIVCK\Cloud\Enums\DnsRecordType;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\IpVersion;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\JsonObject;
use LIVCK\Cloud\Support\KeepSecret;
use LIVCK\Cloud\Support\TagIds;

/**
 * A partial update for `PATCH /v1/services/{id}`: only what was set is sent.
 *
 *     UpdateService::make()->withName('Shop (EU)')->withIntervalSeconds(30)
 *
 * What the server does with each part:
 *
 *  - `name`, `target` and every `settings` key stand alone: an omitted key keeps its
 *    stored value, an omitted `settings` block keeps the whole configuration. The check
 *    type cannot change.
 *  - `settings.config` is merged PER KEY. A key that is sent replaces that whole block:
 *    `headers` replaces every header, `auth` the whole auth block, `conditions` the whole
 *    list (an empty list resets to the type's defaults). Stored secrets survive only when
 *    their value is the keep sentinel ({@see KeepSecret::keep()}); a sentinel where nothing
 *    is stored yet (a new header name, an auth field never set) is a 422.
 *    {@see basedOn()} starts from the current settings with every secret kept, so a single
 *    header can be changed without touching the others.
 *  - `tags` REPLACES the service's tags. A reseller who shares a service between several
 *    customer tags must send the whole set (`$service->tagIds()` plus the new one);
 *    {@see withoutTags()} clears them. Tags a server agent maintains stay either way.
 */
final readonly class UpdateService
{
    /**
     * @param array<string, mixed> $attributes top-level keys: `name`, `target`, `tags`, overrides
     * @param array<string, mixed> $settings `settings` keys, `config` excluded
     * @param array<string, mixed> $config `settings.config` keys
     */
    private function __construct(
        private array $attributes = [],
        private array $settings = [],
        private array $config = [],
    ) {}

    public static function make(): self
    {
        return new self();
    }

    /**
     * Start from the service's current settings: interval, timeout, retries, locations,
     * roles and the whole config with every secret as the keep sentinel. Change what you
     * need and send the rest back unchanged. Name, target and tags are not prefilled.
     *
     * @throws InvalidArgumentException for a service that is not configured (nothing to start from)
     */
    public static function basedOn(Service $current): self
    {
        $settings = $current->settings;

        if (! $settings instanceof ServiceSettings) {
            throw new InvalidArgumentException(sprintf(
                'Service %s is not configured yet, so there are no settings to base an update on; set them from scratch instead.',
                $current->id,
            ));
        }

        $prefilled = [
            'interval_seconds' => $settings->intervalSeconds,
            'timeout_seconds' => $settings->timeoutSeconds,
            'retries' => $settings->retries,
        ];

        if ($settings->assignedProbes !== null) {
            $prefilled['assigned_probes'] = $settings->assignedProbes;
        }

        $roles = $settings->raw['probe_roles'] ?? null;

        if (is_array($roles) && $roles !== []) {
            $prefilled['probe_roles'] = $roles;
        }

        return new self([], $prefilled, $settings->config);
    }

    public function withName(string $name): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A service name must not be blank.');
        }

        return $this->withAttribute('name', $name);
    }

    /**
     * The new target, in the type's format (`https://…`, `host:port`, a host name). A
     * monitored service cannot lose its target.
     */
    public function withTarget(string $target): self
    {
        if (trim($target) === '') {
            throw new InvalidArgumentException('A target must not be blank.');
        }

        return $this->withAttribute('target', $target);
    }

    /**
     * The service's tags after the update, by id or Tag DTO: the WHOLE set, not an
     * addition. The API takes tag ids only, never labels.
     */
    public function withTags(Tag|string ...$tags): self
    {
        return $this->withAttribute('tags', TagIds::of(...$tags));
    }

    /** Remove every tag (except those a server agent maintains). */
    public function withoutTags(): self
    {
        return $this->withAttribute('tags', []);
    }

    /** Seconds between two checks; the plan's minimum and the type's range apply (422). */
    public function withIntervalSeconds(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('The check interval must be at least one second.');
        }

        return $this->withSetting('interval_seconds', $seconds);
    }

    /** Seconds a single check may take (1 to 60). */
    public function withTimeoutSeconds(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('The check timeout must be at least one second.');
        }

        return $this->withSetting('timeout_seconds', $seconds);
    }

    /** Failed attempts before a check counts as failed (0 to 5). */
    public function withRetries(int $retries): self
    {
        if ($retries < 0) {
            throw new InvalidArgumentException('Retries must not be negative.');
        }

        return $this->withSetting('retries', $retries);
    }

    /** The monitoring locations after the update, by code (the whole set). */
    public function withProbes(string ...$codes): self
    {
        if ($codes === []) {
            throw new InvalidArgumentException('withProbes() needs at least one location code.');
        }

        foreach ($codes as $code) {
            if (trim($code) === '') {
                throw new InvalidArgumentException('A location code must not be blank.');
            }
        }

        return $this->withSetting('assigned_probes', array_values(array_unique($codes)));
    }

    /**
     * The role map after the update (the whole map; a location absent from it is `full`).
     *
     * @param array<string, ProbeRole> $roles location code => role
     */
    public function withProbeRoles(array $roles): self
    {
        $map = [];

        foreach ($roles as $code => $role) {
            $map[$code] = $this->wireValue($role);
        }

        return $this->withSetting('probe_roles', JsonObject::of($map));
    }

    /** Drop the service's own role map; the organization's roles apply again. */
    public function withoutProbeRoles(): self
    {
        return $this->withSetting('probe_roles', null);
    }

    /** The request method of an HTTP check. */
    public function withMethod(HttpMethod $method): self
    {
        return $this->withConfig('method', $this->wireValue($method));
    }

    /**
     * The request headers of an HTTP check after the update: the whole map. A value that
     * is the keep sentinel keeps the stored secret of a header of that name.
     *
     * @param array<string, string|KeepSecret> $headers name => value
     */
    public function withHeaders(array $headers): self
    {
        return $this->withConfig('headers', JsonObject::of($headers));
    }

    /**
     * Set one request header, keeping the others known to this payload. Without
     * {@see basedOn()} the payload knows none, and the header map sent replaces every
     * stored header; start from `basedOn()` to change one header among many.
     */
    public function withHeader(string $name, string|KeepSecret $value): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A header name must not be blank.');
        }

        $headers = $this->headers();
        $headers[$name] = $value;

        return $this->withConfig('headers', JsonObject::of($headers));
    }

    /** Remove one request header from those known to this payload (see {@see withHeader()}). */
    public function withoutHeader(string $name): self
    {
        $headers = $this->headers();
        unset($headers[$name]);

        return $this->withConfig('headers', JsonObject::of($headers));
    }

    /** The auth block of an HTTP check after the update (the whole block). */
    public function withAuth(HttpAuth $auth): self
    {
        return $this->withConfig('auth', $auth->toArray());
    }

    /** The request body of an HTTP check. */
    public function withBody(string $body): self
    {
        return $this->withConfig('body', $body);
    }

    public function withFollowRedirects(bool $follow = true): self
    {
        return $this->withConfig('follow_redirects', $follow);
    }

    public function withVerifySsl(bool $verify = true): self
    {
        return $this->withConfig('verify_ssl', $verify);
    }

    public function withIpVersion(IpVersion $version): self
    {
        return $this->withConfig('ip_version', $this->wireValue($version));
    }

    public function withSmartDualstack(bool $enabled = true): self
    {
        return $this->withConfig('smart_dualstack', $enabled);
    }

    /** The record type of a DNS check; the conditions must fit the new type. */
    public function withDnsRecordType(DnsRecordType $type): self
    {
        return $this->withConfig('dns_type', $this->wireValue($type));
    }

    /** The conditions after the update: the whole list. */
    public function withConditions(Condition ...$conditions): self
    {
        return $this->withConfig('conditions', array_map(static fn(Condition $condition): array => $condition->toArray(), array_values($conditions)));
    }

    /** Drop every condition; the server seeds the type's defaults again. */
    public function withDefaultConditions(): self
    {
        return $this->withConfig('conditions', []);
    }

    /** Any `settings.config` key, sent as given (it replaces that key's stored block). */
    public function withConfig(string $key, mixed $value): self
    {
        return new self($this->attributes, $this->settings, [...$this->config, $this->key($key) => $value]);
    }

    /** Any `settings` key, sent as given. */
    public function withSetting(string $key, mixed $value): self
    {
        return new self($this->attributes, [...$this->settings, $this->key($key) => $value], $this->config);
    }

    /** Any top-level key, sent as given. */
    public function withAttribute(string $key, mixed $value): self
    {
        return new self([...$this->attributes, $this->key($key) => $value], $this->settings, $this->config);
    }

    public function isEmpty(): bool
    {
        return $this->attributes === [] && $this->settings === [] && $this->config === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = $this->attributes;
        $settings = $this->settings;

        if ($this->config !== [] && ! array_key_exists('config', $settings)) {
            $settings['config'] = $this->config;
        }

        if ($settings !== []) {
            $payload['settings'] = $settings;
        }

        return $payload;
    }

    /**
     * The header map known to this payload (from `basedOn()` or earlier header calls).
     *
     * @return array<string, mixed>
     */
    private function headers(): array
    {
        $current = $this->config['headers'] ?? null;

        if ($current instanceof JsonObject) {
            return $current->toArray();
        }

        if (is_array($current) && ($current === [] || ! array_is_list($current))) {
            /** @var array<string, mixed> $current */
            return $current;
        }

        return [];
    }

    private function wireValue(ApiEnum&BackedEnum $value): string
    {
        if ($value->isUnrecognized()) {
            throw new InvalidArgumentException(sprintf('%s::Unrecognized stands for a value this SDK version does not know and cannot be sent.', $value::class));
        }

        return (string) $value->value;
    }

    private function key(string $key): string
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A key must not be blank.');
        }

        return $key;
    }
}
