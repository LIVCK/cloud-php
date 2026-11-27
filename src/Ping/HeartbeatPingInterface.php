<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Ping;

/**
 * The signals a job sends to its heartbeat. No method throws: every outcome, a refused or
 * lost ping included, comes back as a {@see PingResult}.
 *
 * Type against this interface and mock it in tests; do not implement it in your own classes,
 * minor releases may add methods.
 */
interface HeartbeatPingInterface
{
    /**
     * The run has started (`/start`). With a maximum duration set on the heartbeat, LIVCK
     * expects the finishing ping within it; LIVCK also measures the run up to the next
     * {@see success()}.
     */
    public function start(?PingDetails $details = null): PingResult;

    /** The run succeeded (the URL itself). */
    public function success(?PingDetails $details = null): PingResult;

    /** The run failed (`/fail`). */
    public function fail(?PingDetails $details = null): PingResult;

    /**
     * The run ended with this exit code (`/<code>`): 0 is a success, anything else a failure.
     * A negative code, which no process exits with but `proc_close()` may return, is sent
     * as `/fail`.
     */
    public function exitCode(int $code, ?PingDetails $details = null): PingResult;
}
