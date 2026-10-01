<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Enums\EnrollmentKeyStatus;
use LIVCK\Cloud\Enums\EnrollmentKeyType;
use LIVCK\Cloud\Support\Field;

/**
 * An enrollment key (`/v1/enrollment-keys`): the credential the install command gives a server
 * so that it enrolls with the organization, as an `agent` service.
 *
 * The key itself is never read back: only {@see CreatedEnrollmentKey} carries it, once.
 * `tokenPrefix` tells keys apart. A `single` key is exhausted once its server enrolled, and
 * `services` then names that server.
 */
final readonly class EnrollmentKey
{
    /**
     * @param string $name shown in the console and the key list; a key created without one gets a default name
     * @param string $tokenPrefix the first characters of the key (`lve_…`)
     * @param list<EnrollmentKeyTag>|null $tags every server enrolled with the key gets these, and the server cannot
     *                                          replace them (every enrollment-key endpoint includes them)
     * @param bool $agentTags whether a server may add tags of its own at install; always false in a reseller organization
     * @param int $uses how many servers enrolled with the key, all of them
     * @param int $maxUses how many servers may enroll; 1 for a `single` key
     * @param DateTimeImmutable|null $revokedAt null while the key is not revoked
     * @param list<Reference>|null $services the servers enrolled with the key, newest first, at most the 25 newest
     *                                       (every enrollment-key endpoint includes them)
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $id,
        public EnrollmentKeyType $type,
        public string $name,
        public string $tokenPrefix,
        public ?array $tags,
        public bool $agentTags,
        public int $uses,
        public int $maxUses,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt,
        public EnrollmentKeyStatus $status,
        public ?array $services,
        public DateTimeImmutable $createdAt,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'id'),
            EnrollmentKeyType::fromApi(Field::string($data, 'type')),
            Field::string($data, 'name'),
            Field::string($data, 'token_prefix'),
            array_key_exists('tags', $data) ? array_map(EnrollmentKeyTag::fromArray(...), Field::objectList($data, 'tags')) : null,
            Field::bool($data, 'agent_tags'),
            Field::int($data, 'uses'),
            Field::int($data, 'max_uses'),
            Field::instant($data, 'expires_at'),
            Field::nullableInstant($data, 'revoked_at'),
            EnrollmentKeyStatus::fromApi(Field::string($data, 'status')),
            array_key_exists('services', $data) ? array_map(Reference::fromArray(...), Field::objectList($data, 'services')) : null,
            Field::instant($data, 'created_at'),
            $data,
        );
    }

    /** Whether the key can still enroll a server. */
    public function isActive(): bool
    {
        return $this->status === EnrollmentKeyStatus::Active;
    }

    /**
     * The server that enrolled last, or null while none has: for a `single` key, the one server
     * it was made for.
     */
    public function latestService(): ?Reference
    {
        return ($this->services ?? [])[0] ?? null;
    }

    /**
     * The ids of the key's tags.
     *
     * @return list<string>
     */
    public function tagIds(): array
    {
        return array_map(static fn(EnrollmentKeyTag $tag): string => $tag->id, $this->tags ?? []);
    }

    /** Whether the key carries a tag with exactly this label (`customer:4711`). */
    public function hasTag(string $label): bool
    {
        foreach ($this->tags ?? [] as $tag) {
            if ($tag->label === $label) {
                return true;
            }
        }

        return false;
    }
}
