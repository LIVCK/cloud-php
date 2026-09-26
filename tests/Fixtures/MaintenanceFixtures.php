<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Fixtures;

/**
 * Maintenance windows as `MaintenanceResource` writes them.
 */
final class MaintenanceFixtures
{
    public const string ID = 'kN3vQ8pLm2Rt7Yx4Wz9Ab';

    /**
     * A scheduled window as the lists carry it (services included, no statuspages, no
     * timeline).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        return [
            'id' => self::ID,
            'title' => 'Kernel update',
            'title_translations' => null,
            'type' => 'planned',
            'status' => 'scheduled',
            'scheduled_start' => '2026-10-01T22:00:00+00:00',
            'scheduled_end' => '2026-10-02T00:00:00+00:00',
            'auto_start' => true,
            'auto_complete' => true,
            'created_at' => '2026-09-20T09:15:00+00:00',
            'attachments' => [],
            'services' => [['id' => ServiceFixtures::ID, 'name' => 'API health']],
            ...$overrides,
        ];
    }

    /**
     * The same window as `GET /v1/maintenances/{id}` returns it.
     *
     * @return array<string, mixed>
     */
    public static function detailed(): array
    {
        return self::payload([
            'title_translations' => ['de' => 'Kernel-Update', 'en' => 'Kernel update'],
            'statuspages' => [['id' => 'sP4gE1dXyZ9aBcDeFgHiJ', 'name' => 'Customer status']],
            'updates' => [
                ['id' => 'mU1pDaTe2XyZ3aBcDeFgH', 'status' => 'scheduled', 'message' => 'Planned reboot of the database hosts.', 'created_at' => '2026-09-20T09:15:00+00:00'],
            ],
        ]);
    }
}
