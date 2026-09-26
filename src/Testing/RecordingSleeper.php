<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Testing;

use LIVCK\Cloud\Http\Sleeper;

/**
 * Records the waits a retry would have slept instead of sleeping them.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    private array $delays = [];

    public function sleep(float $seconds): void
    {
        $this->delays[] = $seconds;
    }

    /**
     * @return list<float>
     */
    public function delays(): array
    {
        return $this->delays;
    }

    public function total(): float
    {
        return array_sum($this->delays);
    }
}
