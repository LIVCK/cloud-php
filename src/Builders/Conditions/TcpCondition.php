<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

/**
 * Conditions on a TCP check.
 *
 *     TcpCondition::responseTimeMs()->gt(500)->degraded()
 *     TcpCondition::resolvedIpCount()->lt(2)
 */
final readonly class TcpCondition extends Condition
{
    /**
     * Time to connect, in milliseconds.
     *
     * @return OrderedSubject<self>
     */
    public static function responseTimeMs(): OrderedSubject
    {
        return new OrderedSubject('response_time_ms', self::class);
    }

    /**
     * How many addresses the host name resolved to.
     *
     * @return NumberSubject<self>
     */
    public static function resolvedIpCount(): NumberSubject
    {
        return new NumberSubject('metadata.resolved_ip_count', self::class);
    }
}
