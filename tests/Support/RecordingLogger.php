<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps every log record for assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : get_debug_type($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
