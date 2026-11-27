<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

/**
 * Whether a host is the local machine: `localhost`, `*.localhost`, `127.0.0.0/8` or `::1`.
 *
 * The one place that decides where plain http is acceptable (a development stack), for the
 * API base URI and for heartbeat ping URLs alike.
 */
final class LoopbackHost
{
    private function __construct() {}

    /** @param string $host as `parse_url()` returns it; IPv6 may keep its brackets */
    public static function matches(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return inet_pton($host) === inet_pton('::1');
        }

        return false;
    }
}
