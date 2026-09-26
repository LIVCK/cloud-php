<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Enums\TokenType;
use LIVCK\Cloud\Support\Field;

/**
 * The calling token, as `GET /v1/me` describes it: its type, abilities, organization and
 * rate limit. Reachable with any token; a good first call to verify a configuration.
 */
final readonly class Me
{
    /**
     * @param list<string> $permissions the abilities the token carries (`services.view`, …)
     * @param MeService|null $service the service a server agent's token is bound to; null for an organization token
     * @param DateTimeImmutable|null $expiresAt null for a token that never expires
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public TokenType $type,
        public array $permissions,
        public MeOrganization $organization,
        public MeRateLimit $rateLimit,
        public ?MeService $service,
        public ?DateTimeImmutable $expiresAt,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $service = Field::nullableObject($data, 'service');

        return new self(
            TokenType::fromApi(Field::string($data, 'type')),
            Field::stringList($data, 'permissions'),
            MeOrganization::fromArray(Field::object($data, 'organization')),
            MeRateLimit::fromArray(Field::object($data, 'rate_limit')),
            $service === null ? null : MeService::fromArray($service),
            Field::nullableInstant($data, 'expires_at'),
            $data,
        );
    }

    /** Whether the token carries an ability, e.g. `services.create`. */
    public function can(string $ability): bool
    {
        return in_array($ability, $this->permissions, true);
    }

    public function isManaged(): bool
    {
        return $this->type === TokenType::Managed;
    }

    public function expires(): bool
    {
        return $this->expiresAt instanceof DateTimeImmutable;
    }
}
