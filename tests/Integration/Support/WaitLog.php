<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Integration\Support;

use LIVCK\Cloud\Http\Sleeper;

/**
 * The sleeper of every live client: it waits for real, like the default, and remembers
 * every wait, so a scenario can show that the SDK honoured a `Retry-After` (a rate limit,
 * a per-domain verification cooldown) and how long that took.
 */
final class WaitLog implements Sleeper
{
    /** @var list<float> */
    private array $waits = [];

    public function sleep(float $seconds): void
    {
        $this->waits[] = $seconds;

        if ($seconds > 0.0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    public function count(): int
    {
        return count($this->waits);
    }

    public function total(): float
    {
        return (float) array_sum($this->waits);
    }

    /**
     * The waits recorded after a mark taken with {@see count()}.
     *
     * @return list<float>
     */
    public function since(int $mark): array
    {
        return array_slice($this->waits, $mark);
    }

    public function summary(): string
    {
        return sprintf('%d retry wait%s, %.1f s in total', $this->count(), $this->count() === 1 ? '' : 's', $this->total());
    }
}
