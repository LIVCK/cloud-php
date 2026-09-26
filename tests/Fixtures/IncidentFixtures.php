<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Fixtures;

/**
 * Incidents as `IncidentResource` / `ServiceIncidentResource` write them.
 */
final class IncidentFixtures
{
    public const string ID = 'ykgQRJ2GQQl5vQgDQdFXY';

    /**
     * A resolved, detected incident as the org-wide list carries it (services included,
     * no timeline).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        return [
            'id' => self::ID,
            'title' => 'API health is experiencing problems',
            'title_translations' => ['de' => 'API health hat derzeit Probleme', 'en' => 'API health is experiencing problems'],
            'status' => 'resolved',
            'kind' => 'standard',
            'severity' => 'critical',
            'is_published' => true,
            'started_at' => '2026-09-13T19:03:15+00:00',
            'resolved_at' => '2026-09-13T19:08:03+00:00',
            'created_at' => '2026-09-13T19:03:15+00:00',
            'attachments' => [],
            'services' => [['id' => ServiceFixtures::ID, 'name' => 'API health']],
            ...$overrides,
        ];
    }

    /**
     * The same incident as `GET /v1/incidents/{id}` returns it, timeline included.
     *
     * @return array<string, mixed>
     */
    public static function detailed(): array
    {
        return self::payload([
            'attachments' => [['id' => '9b0e7c1e-4a2f-4d0b-9a3e-1f2c3d4e5f60', 'name' => 'postmortem.pdf', 'size' => 48211, 'url' => 'https://files.livck.cloud/attachments/postmortem.pdf']],
            'updates' => [
                ['id' => 'Agx9NgNUZKbxwn8YX1uK2', 'status' => 'investigating', 'message' => 'Detected automatically: API health is down.', 'notify_subscribers' => true, 'created_at' => '2026-09-13T19:03:15+00:00'],
                ['id' => 'BuFHcY6eBGTngaxeovAx8', 'status' => 'resolved', 'message' => 'Every affected service is reachable again.', 'notify_subscribers' => true, 'created_at' => '2026-09-13T19:08:03+00:00'],
            ],
        ]);
    }

    /**
     * An open incident as `GET /v1/services/{id}/incidents` lists it, with the service's
     * own impact block.
     *
     * @return array<string, mixed>
     */
    public static function withImpact(): array
    {
        return self::payload([
            'id' => '3WJUuQKvDVMx0YxfT5I03',
            'status' => 'investigating',
            'severity' => 'major',
            'started_at' => '2026-09-25T12:00:00+00:00',
            'resolved_at' => null,
            'created_at' => '2026-09-25T12:00:00+00:00',
            'service_impact' => [
                'impact' => 'partial_outage',
                'is_recovered' => false,
                'added_at' => '2026-09-25T12:00:00+00:00',
                'recovered_at' => null,
            ],
        ]);
    }
}
