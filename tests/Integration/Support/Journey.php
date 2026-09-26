<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Integration\Support;

use Closure;
use Throwable;

/**
 * Runs the steps of a journey in order and keeps a log of how each went and how long it
 * took, printed at the end whether the journey passed or failed.
 */
final class Journey
{
    /** @var list<string> */
    private array $log = [];

    /**
     * @template T
     * @param Closure(): T $body
     * @return T
     */
    public function step(string $name, Closure $body): mixed
    {
        $started = hrtime(true);

        try {
            $result = $body();
            $this->log[] = sprintf('%-52s passed  %6.2f s', $name, $this->seconds($started));

            return $result;
        } catch (Throwable $e) {
            $this->log[] = sprintf('%-52s FAILED  %6.2f s  %s', $name, $this->seconds($started), $e->getMessage());

            throw $e;
        }
    }

    public function note(string $text): void
    {
        $this->log[] = '    ' . $text;
    }

    public function report(): string
    {
        return implode("\n", $this->log);
    }

    private function seconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1e9;
    }
}
