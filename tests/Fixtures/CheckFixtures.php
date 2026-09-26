<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Fixtures;

/**
 * Check results as `CheckResultResource::toArray()` writes them.
 */
final class CheckFixtures
{
    /**
     * A passed HTTP check with every timing phase.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        return [
            'checked_at' => '2026-09-20T10:00:00.000Z',
            'probe' => 'ffm',
            'status' => 'up',
            'response_time_ms' => 120,
            'status_code' => 200,
            'error_message' => null,
            'timings' => ['dns' => 5, 'connect' => 10, 'tls' => 20, 'ttfb' => 80, 'transfer' => 5, 'total' => 120],
            ...$overrides,
        ];
    }

    /**
     * A timed-out check: no status code, no phases, a sanitized reason.
     *
     * @return array<string, mixed>
     */
    public static function failed(): array
    {
        return self::payload([
            'checked_at' => '2026-09-20T10:01:00.472Z',
            'probe' => 'hel',
            'status' => 'down',
            'response_time_ms' => 10000,
            'status_code' => null,
            'error_message' => 'Request timed out',
            'timings' => ['dns' => null, 'connect' => null, 'tls' => null, 'ttfb' => null, 'transfer' => null, 'total' => null],
        ]);
    }
}
