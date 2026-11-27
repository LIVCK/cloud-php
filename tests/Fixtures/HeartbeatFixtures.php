<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Fixtures;

/**
 * The answer to a heartbeat declaration as `HeartbeatDeclarationResource::toArray()` writes it.
 */
final class HeartbeatFixtures
{
    public const string NIGHTLY_ID = 'ShEreD7Ym2y4RLgAStUKO';

    public const string QUEUE_ID = 'Qu3ueW0rkerDefault01x';

    public const string UNLISTED_ID = 'HxtDBn4SzCLIwTmuMuyPd';

    public const string NIGHTLY_TOKEN = 'hb_N7xQ2mV9kL4pR8wT3nB6cY1dF5gH0jS2aE7uI4oZ';

    public const string QUEUE_TOKEN = 'hb_Q1wE2rT3yU4iO5pA6sD7fG8hJ9kL0zX1cV2bN3mQ';

    public const string NIGHTLY_URL = 'https://ping.livck.cloud/' . self::NIGHTLY_TOKEN;

    public const string QUEUE_URL = 'https://ping.livck.cloud/' . self::QUEUE_TOKEN;

    /**
     * A declaration of three heartbeats: the nightly export was left alone, the queue was
     * aligned, the new import job could not be created (all new ones or none); a fourth heartbeat
     * of the application is unlisted.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function result(array $overrides = []): array
    {
        return [
            'heartbeats' => [self::nightly(), self::queue(['result' => 'updated'])],
            'not_created' => self::notCreated(),
            'unlisted' => [['key' => 'report:weekly', 'service_id' => self::UNLISTED_ID]],
            'skipped_tags' => [],
            'counts' => ['created' => 0, 'updated' => 1, 'unchanged' => 1],
            ...$overrides,
        ];
    }

    /**
     * Everything declared exists: the queue was created, nothing else is known.
     *
     * @return array<string, mixed>
     */
    public static function complete(): array
    {
        return self::result([
            'heartbeats' => [self::nightly(), self::queue()],
            'not_created' => self::nothingMissing(),
            'unlisted' => [],
            'skipped_tags' => ['team:payments'],
            'counts' => ['created' => 1, 'updated' => 0, 'unchanged' => 1],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function nightly(array $overrides = []): array
    {
        return [
            'service_id' => self::NIGHTLY_ID,
            'key' => 'export:nightly',
            'name' => 'export:nightly',
            'kind' => 'schedule',
            'schedule' => ['type' => 'cron', 'period_seconds' => null, 'expression' => '0 3 * * *', 'timezone' => 'Europe/Berlin'],
            'grace_seconds' => 60,
            'schedule_tolerance' => 1,
            'max_duration_seconds' => null,
            'ping_url' => self::NIGHTLY_URL,
            'status' => 'unknown',
            'paused' => false,
            'result' => 'unchanged',
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function queue(array $overrides = []): array
    {
        return [
            'service_id' => self::QUEUE_ID,
            'key' => 'queue:default',
            'name' => 'Default queue',
            'kind' => 'queue',
            'schedule' => ['type' => 'simple', 'period_seconds' => 60, 'expression' => null, 'timezone' => 'UTC'],
            'grace_seconds' => 120,
            'schedule_tolerance' => 2,
            'max_duration_seconds' => 900,
            'ping_url' => self::QUEUE_URL,
            'status' => 'up',
            'paused' => false,
            'result' => 'created',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function notCreated(): array
    {
        return ['keys' => ['App/Jobs/ImportCustomers'], 'reason' => 'service_limit', 'missing' => 1, 'retry_after' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public static function nothingMissing(): array
    {
        return ['keys' => [], 'reason' => null, 'missing' => null, 'retry_after' => null];
    }
}
