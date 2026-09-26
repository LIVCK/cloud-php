<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Integration\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Counts the attempts the SDK's transport logs (one debug line per attempt, with the
 * response status), so a scenario can assert that a client-side refusal sent nothing and
 * can report how often the server throttled the run.
 */
final class AttemptLog extends AbstractLogger
{
    private int $attempts = 0;

    /** @var array<string, int> */
    private array $byStatus = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (! array_key_exists('attempt', $context)) {
            return;
        }

        $this->attempts++;
        $status = $context['status'] ?? 'unknown';
        $key = is_scalar($status) ? (string) $status : get_debug_type($status);
        $this->byStatus[$key] = ($this->byStatus[$key] ?? 0) + 1;
    }

    /** How many requests went out so far (retries included). */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /** How many attempts ended with a status (`429`) or without a response (`transport error`). */
    public function withStatus(int|string $status): int
    {
        return $this->byStatus[(string) $status] ?? 0;
    }

    public function summary(): string
    {
        $parts = [];
        ksort($this->byStatus);

        foreach ($this->byStatus as $status => $count) {
            $parts[] = sprintf('%s: %d', $status, $count);
        }

        return sprintf('%d attempts (%s)', $this->attempts, implode(', ', $parts));
    }
}
