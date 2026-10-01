<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Fixtures;

/**
 * Enrollment keys as `EnrollmentKeyResource::toArray()` writes them, and the create response of
 * `CreatedEnrollmentKeyResource` with the key and the install command.
 */
final class EnrollmentKeyFixtures
{
    public const string ID = 'Ek7pXv2LkT9sWb4NcR8aQ';

    /** A key as the server mints it: `lve_` and 40 characters. */
    public const string TOKEN = 'lve_Z8mQ2vR7kL4pX9wT3nB6cY1dF5gH0jS2aE7uI4oP';

    public const string TOKEN_PREFIX = 'lve_Z8mQ2vR7';

    public const string INSTALL_COMMAND = 'curl -fsSL https://get.livck.cloud/install.sh | sudo sh -s -- --token ' . self::TOKEN;

    /**
     * A single key, unused, with the customer's tag.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        return [
            'id' => self::ID,
            'type' => 'single',
            'name' => 'Customer 4711',
            'token_prefix' => self::TOKEN_PREFIX,
            'tags' => [self::tag()],
            'agent_tags' => true,
            'uses' => 0,
            'max_uses' => 1,
            'expires_at' => '2026-10-02T12:00:00+00:00',
            'revoked_at' => null,
            'status' => 'active',
            'services' => [],
            'created_at' => '2026-10-01T12:00:00+00:00',
            ...$overrides,
        ];
    }

    /**
     * The key after its server enrolled.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function exhausted(array $overrides = []): array
    {
        return self::payload([
            'uses' => 1,
            'status' => 'exhausted',
            'services' => [['id' => ServiceFixtures::AGENT_ID, 'name' => 'web-1']],
            ...$overrides,
        ]);
    }

    /**
     * A fleet key, revoked after two servers enrolled with it; newest first.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function revokedFleet(array $overrides = []): array
    {
        return self::payload([
            'id' => 'Fl3eTk9pQ8rS7tU6vW5xY',
            'type' => 'fleet',
            'name' => 'Web servers',
            'token_prefix' => 'lve_9Hn2LkQ4',
            'tags' => [],
            'agent_tags' => false,
            'uses' => 2,
            'max_uses' => 200,
            'expires_at' => '2026-12-30T12:00:00+00:00',
            'revoked_at' => '2026-10-01T15:30:00+00:00',
            'status' => 'revoked',
            'services' => [
                ['id' => 'Wb2srV9aB3cD5eF7gH1iJ', 'name' => 'web-2'],
                ['id' => ServiceFixtures::AGENT_ID, 'name' => 'web-1'],
            ],
            ...$overrides,
        ]);
    }

    /**
     * The `data` of the create response: the key, its value and the install command.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function created(array $overrides = []): array
    {
        return [
            ...self::payload(),
            'token' => self::TOKEN,
            'install_command' => self::INSTALL_COMMAND,
            ...$overrides,
        ];
    }

    /**
     * A tag as a key carries it: no `source`, no `services_count`.
     *
     * @return array<string, mixed>
     */
    public static function tag(): array
    {
        return [
            'id' => ServiceFixtures::TAG_ID,
            'key' => 'kunde',
            'value' => '4711',
            'color' => '#6366f1',
            'label' => 'kunde:4711',
        ];
    }
}
