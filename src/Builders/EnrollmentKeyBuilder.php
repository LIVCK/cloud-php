<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use DateTimeImmutable;
use DateTimeInterface;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\EnrollmentKeyType;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\TagEntries;

/**
 * A new enrollment key for `POST /v1/enrollment-keys`. Pick the kind, then add options:
 *
 *     EnrollmentKeyBuilder::single('Customer 4711')->tags($customerTag)   // one server
 *     EnrollmentKeyBuilder::fleet('Web servers', maxUses: 200)            // images, cloud-init
 *
 * Like every builder, it is immutable: each option returns a new builder. Unset options take the
 * server's defaults: a name of its own, no tags, agent tags allowed (never in a reseller
 * organization), and a lifetime of 24 hours for a single key and 90 days for a fleet key.
 *
 * Nothing the server may accept tomorrow needs a new SDK release: {@see attribute()} writes any
 * key of the body.
 */
final readonly class EnrollmentKeyBuilder
{
    /**
     * @param array<string, mixed> $attributes
     */
    private function __construct(
        private array $attributes,
    ) {}

    /**
     * A key for one server: it works once and is exhausted afterwards. At most 7 days valid.
     *
     * @param string|null $name shown in the console and the key list, up to 100 characters
     */
    public static function single(?string $name = null): self
    {
        return new self(self::named(['type' => EnrollmentKeyType::Single->value], $name));
    }

    /**
     * A key for many servers, for images, cloud-init or configuration management: up to
     * `$maxUses` servers enroll with it (1 to 10,000; 50 when null). At most 365 days valid.
     *
     * @param string|null $name shown in the console and the key list, up to 100 characters
     */
    public static function fleet(?string $name = null, ?int $maxUses = null): self
    {
        $attributes = self::named(['type' => EnrollmentKeyType::Fleet->value], $name);

        if ($maxUses !== null) {
            $attributes['max_uses'] = $maxUses;
        }

        return new self($attributes);
    }

    /**
     * The tags every server enrolled with the key gets: Tag objects, tag ids and tag names
     * (`customer:4711`, `env=prod`) in any mix, resolved like a service's tags
     * ({@see ServiceBuilder::tags()}); a name that does not exist yet is created with the key.
     * A server cannot replace them: a tag the install asks for under the same key is left off.
     * The customer's tag may also fill a synced status page group: the server gets it, but
     * synced groups leave servers out, so it never shows on the page.
     *
     * @throws InvalidArgumentException for a blank entry, before anything is sent
     */
    public function tags(Tag|string ...$tags): self
    {
        return $this->with('tags', TagEntries::of(...$tags));
    }

    /**
     * When the key stops working; it must lie in the future, at most 7 days ahead for a single
     * key and 365 days for a fleet key.
     */
    public function expiresAt(DateTimeInterface $expiresAt): self
    {
        // A copy of a mutable DateTime: the caller changing it later must not change this builder.
        return $this->with('expires_at', $expiresAt instanceof DateTimeImmutable ? $expiresAt : DateTimeImmutable::createFromInterface($expiresAt));
    }

    /**
     * Whether a server may add tags of its own at install (`--tag`). They only ever add to the
     * key's tags. Allowed unless switched off; a reseller organization cannot allow it (a
     * `ValidationException` on `agent_tags`) and its keys never do.
     */
    public function allowAgentTags(bool $allow = true): self
    {
        return $this->with('agent_tags', $allow);
    }

    /**
     * Any top-level key of the body, sent as given; overrides a typed value of the same key.
     */
    public function attribute(string $key, mixed $value): self
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A key must not be blank.');
        }

        return $this->with($key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function named(array $attributes, ?string $name): array
    {
        if ($name === null) {
            return $attributes;
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('A key name must not be blank; pass null to let the server name the key.');
        }

        return [...$attributes, 'name' => $name];
    }

    private function with(string $field, mixed $value): self
    {
        return new self([...$this->attributes, $field => $value]);
    }
}
