<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Ping;

/**
 * What a heartbeat ping may carry besides its signal: figures of the run and an excerpt of
 * its log.
 *
 *     $details = PingDetails::make()
 *         ->withDuration($seconds)
 *         ->withMetric('rows', 1520)
 *         ->withLog($output);
 *
 * Built to the limits LIVCK applies to a ping body, so that what is set here arrives:
 *
 * - at most {@see MAX_METRICS} figures, each a finite number under a key of 1 to 32
 *   characters from `a-z`, `0-9` and `_` that is not a whole number. LIVCK discards anything else, so an entry that
 *   does not fit is left out here and listed by {@see droppedMetrics()} (and again on the
 *   {@see PingResult}) instead of throwing inside your job.
 * - a log of at most {@see MAX_LOG_BYTES} bytes of UTF-8. A longer log keeps its END, where a
 *   failing job usually says why, and is cut on a character boundary. Invalid UTF-8 is
 *   replaced and control characters other than tab and line breaks are removed, as LIVCK
 *   would do.
 * - a body of at most {@see MAX_BODY_BYTES} bytes, or LIVCK records the ping without any of
 *   it. {@see toBody()} shortens the log further when its JSON escapes would pass that.
 *
 * Immutable: every `with…()` returns a copy.
 */
final readonly class PingDetails
{
    /** The conventional key for the run's duration, in seconds. */
    public const string DURATION_METRIC = 'duration';

    public const int MAX_METRICS = 10;

    public const int MAX_LOG_BYTES = 4096;

    public const int MAX_BODY_BYTES = 8192;

    private const string METRIC_KEY_PATTERN = '/\A[a-z0-9_]{1,32}\z/';

    /**
     * @param array<string, int|float> $metrics
     * @param list<string> $droppedMetrics
     */
    private function __construct(
        private array $metrics = [],
        private ?string $log = null,
        private array $droppedMetrics = [],
    ) {}

    public static function make(): self
    {
        return new self();
    }

    /**
     * How long the run took, as the metric `duration` in seconds.
     *
     * LIVCK measures a run on its own from a `start()` to the next `success()`; this figure
     * is reported next to that one, for jobs that are not pinged on start.
     */
    public function withDuration(float $seconds): self
    {
        return $this->withMetric(self::DURATION_METRIC, $seconds);
    }

    /**
     * One figure of the run. The same key again replaces the value. A key outside
     * `a-z0-9_` (1 to 32 characters), a key that is a whole number, a value that is not finite, or an eleventh key is left
     * out and listed by {@see droppedMetrics()}.
     */
    public function withMetric(string $key, int|float $value): self
    {
        if (! $this->isMetricKey($key)
            || (is_float($value) && ! is_finite($value))
            || (! array_key_exists($key, $this->metrics) && count($this->metrics) >= self::MAX_METRICS)) {
            return new self($this->metrics, $this->log, $this->withDropped($key));
        }

        $metrics = $this->metrics;
        $metrics[$key] = $value;

        return new self($metrics, $this->log, $this->droppedMetrics);
    }

    /**
     * Several figures at once, each as {@see withMetric()} takes it. An entry whose key is not
     * a string or whose value is not a number is left out and listed as well.
     *
     * @param array<array-key, mixed> $metrics
     */
    public function withMetrics(array $metrics): self
    {
        $details = $this;

        foreach ($metrics as $key => $value) {
            $details = is_string($key) && (is_int($value) || is_float($value))
                ? $details->withMetric($key, $value)
                : new self($details->metrics, $details->log, $details->withDropped((string) $key));
        }

        return $details;
    }

    /**
     * An excerpt of the run's output. Kept to its last {@see MAX_LOG_BYTES} bytes; a log that
     * is empty once cleaned removes the excerpt.
     */
    public function withLog(string $log): self
    {
        $log = $this->clean($log);

        return new self($this->metrics, $log === '' ? null : $this->tail($log, self::MAX_LOG_BYTES), $this->droppedMetrics);
    }

    /**
     * The figures that will be sent.
     *
     * @return array<string, int|float>
     */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /** The log excerpt as it will be sent (cleaned and shortened), or null. */
    public function log(): ?string
    {
        return $this->log;
    }

    /**
     * Keys of figures that were left out, in the order they were given.
     *
     * @return list<string>
     */
    public function droppedMetrics(): array
    {
        return $this->droppedMetrics;
    }

    public function isEmpty(): bool
    {
        return $this->metrics === [] && $this->log === null;
    }

    /**
     * The body as LIVCK reads it: `metrics` (only when there are any) and `metadata` (the log).
     *
     * @return array{metrics?: array<string, int|float>, metadata?: string}
     */
    public function toArray(): array
    {
        $body = [];

        if ($this->metrics !== []) {
            $body['metrics'] = $this->metrics;
        }

        if ($this->log !== null) {
            $body['metadata'] = $this->log;
        }

        return $body;
    }

    /**
     * The JSON body, at most {@see MAX_BODY_BYTES} bytes; an empty string when there is
     * nothing to send.
     */
    public function toBody(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $body = $this->toArray();

        for (;;) {
            $json = $this->encode($body);
            $excess = strlen($json) - self::MAX_BODY_BYTES;

            if ($excess <= 0 || ! isset($body['metadata'])) {
                return $json;
            }

            // Every byte of the log is at least one byte of JSON, so dropping the excess from
            // its start is enough in one pass; the loop only guards the arithmetic.
            $metadata = $this->tail($body['metadata'], max(0, strlen($body['metadata']) - $excess));

            if ($metadata === '') {
                unset($body['metadata']);
            } else {
                $body['metadata'] = $metadata;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function withDropped(string $key): array
    {
        return [...$this->droppedMetrics, $key];
    }

    /**
     * A key LIVCK keeps. A key that is a whole number (`"42"`) fits the pattern but becomes an
     * integer key when the body is decoded, and LIVCK drops it there.
     */
    private function isMetricKey(string $key): bool
    {
        return preg_match(self::METRIC_KEY_PATTERN, $key) === 1 && (string) (int) $key !== $key;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function encode(array $body): string
    {
        return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Valid UTF-8, without control characters other than tab, line feed and carriage return. */
    private function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

    /** The last $bytes bytes of valid UTF-8 text, never starting inside a character. */
    private function tail(string $text, int $bytes): string
    {
        $length = strlen($text);

        if ($length <= $bytes) {
            return $text;
        }

        $start = $length - $bytes;

        while ($start < $length && (ord($text[$start]) & 0xC0) === 0x80) {
            $start++;
        }

        return substr($text, $start);
    }
}
