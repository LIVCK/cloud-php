<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Http;

/**
 * Blocks the current process. The default outside tests.
 */
final class SystemSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
