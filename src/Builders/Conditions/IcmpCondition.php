<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

/**
 * Conditions on an ICMP (ping) check.
 *
 *     IcmpCondition::packetLossPercent()->gt(0)->degraded()
 *     IcmpCondition::maxRttMs()->gt(250)
 */
final readonly class IcmpCondition extends Condition
{
    /**
     * Round-trip time, in milliseconds.
     *
     * @return OrderedSubject<self>
     */
    public static function responseTimeMs(): OrderedSubject
    {
        return new OrderedSubject('response_time_ms', self::class);
    }

    /**
     * Packets lost, in percent.
     *
     * @return NumberSubject<self>
     */
    public static function packetLossPercent(): NumberSubject
    {
        return new NumberSubject('metadata.packet_loss_pct', self::class);
    }

    /**
     * The slowest round trip, in milliseconds.
     *
     * @return OrderedSubject<self>
     */
    public static function maxRttMs(): OrderedSubject
    {
        return new OrderedSubject('metadata.max_rtt_ms', self::class);
    }

    /**
     * How many echo replies came back.
     *
     * @return NumberSubject<self>
     */
    public static function packetsReceived(): NumberSubject
    {
        return new NumberSubject('metadata.packets_received', self::class);
    }
}
