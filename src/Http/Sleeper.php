<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Http;

/**
 * Where the transport waits between retries. Swapped for a recording implementation in
 * tests so that backoff is asserted, never slept.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
