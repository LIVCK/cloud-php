<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

/**
 * Conditions on a DNS check. Which fields apply depends on the record type: `A`/`AAAA`
 * produce `ipCount()`/`ips()`, `MX` the `mx*` fields, `NS` the `ns*` fields, `TXT` the
 * `txt*` fields and `CNAME` only `cname()`; `responseTimeMs()` applies to every type. A
 * field the record type never produces is a 422.
 *
 *     DnsCondition::ipCount()->eq(0)
 *     DnsCondition::nsRecords()->contains('ns1.example.net.')->degraded()
 */
final readonly class DnsCondition extends Condition
{
    /**
     * Resolve time, in milliseconds.
     *
     * @return OrderedSubject<self>
     */
    public static function responseTimeMs(): OrderedSubject
    {
        return new OrderedSubject('response_time_ms', self::class);
    }

    /**
     * How many addresses an `A`/`AAAA` lookup returned.
     *
     * @return NumberSubject<self>
     */
    public static function ipCount(): NumberSubject
    {
        return new NumberSubject('metadata.ip_count', self::class);
    }

    /**
     * The addresses an `A`/`AAAA` lookup returned.
     *
     * @return ContainsSubject<self>
     */
    public static function ips(): ContainsSubject
    {
        return new ContainsSubject('metadata.ips', self::class);
    }

    /**
     * How many name servers an `NS` lookup returned.
     *
     * @return NumberSubject<self>
     */
    public static function nsCount(): NumberSubject
    {
        return new NumberSubject('metadata.ns_count', self::class);
    }

    /**
     * The name servers an `NS` lookup returned.
     *
     * @return ContainsSubject<self>
     */
    public static function nsRecords(): ContainsSubject
    {
        return new ContainsSubject('metadata.ns_records', self::class);
    }

    /**
     * How many mail servers an `MX` lookup returned.
     *
     * @return NumberSubject<self>
     */
    public static function mxCount(): NumberSubject
    {
        return new NumberSubject('metadata.mx_count', self::class);
    }

    /**
     * The mail servers an `MX` lookup returned.
     *
     * @return ContainsSubject<self>
     */
    public static function mxRecords(): ContainsSubject
    {
        return new ContainsSubject('metadata.mx_records', self::class);
    }

    /**
     * The target of a `CNAME` lookup.
     *
     * @return StringSubject<self>
     */
    public static function cname(): StringSubject
    {
        return new StringSubject('metadata.cname', self::class);
    }

    /**
     * How many records a `TXT` lookup returned.
     *
     * @return NumberSubject<self>
     */
    public static function txtCount(): NumberSubject
    {
        return new NumberSubject('metadata.txt_count', self::class);
    }

    /**
     * The records a `TXT` lookup returned.
     *
     * @return ContainsSubject<self>
     */
    public static function txtRecords(): ContainsSubject
    {
        return new ContainsSubject('metadata.txt_records', self::class);
    }
}
