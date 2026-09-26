<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

/**
 * Conditions on an SSL certificate check.
 *
 *     SslCondition::daysUntilExpiry()->lt(14)->degraded()
 *     SslCondition::daysUntilExpiry()->lt(3)->down()
 *     SslCondition::tlsVersion()->in(['TLS 1.0', 'TLS 1.1'])
 */
final readonly class SslCondition extends Condition
{
    /**
     * Days until the certificate expires.
     *
     * @return NumberSubject<self>
     */
    public static function daysUntilExpiry(): NumberSubject
    {
        return new NumberSubject('metadata.days_until_expiry', self::class);
    }

    /**
     * The certificate's issuer.
     *
     * @return StringSubject<self>
     */
    public static function issuer(): StringSubject
    {
        return new StringSubject('metadata.issuer', self::class);
    }

    /**
     * The certificate's subject (CN).
     *
     * @return StringSubject<self>
     */
    public static function subject(): StringSubject
    {
        return new StringSubject('metadata.subject', self::class);
    }

    /**
     * The certificate's subject alternative names.
     *
     * @return ContainsSubject<self>
     */
    public static function dnsNames(): ContainsSubject
    {
        return new ContainsSubject('metadata.dns_names', self::class);
    }

    /**
     * The negotiated TLS version.
     *
     * @return VersionSubject<self>
     */
    public static function tlsVersion(): VersionSubject
    {
        return new VersionSubject('metadata.tls_version', self::class);
    }

    /**
     * The negotiated cipher suite.
     *
     * @return StringSubject<self>
     */
    public static function cipherSuite(): StringSubject
    {
        return new StringSubject('metadata.cipher_suite', self::class);
    }

    /**
     * How many certificates the presented chain holds.
     *
     * @return NumberSubject<self>
     */
    public static function chainLength(): NumberSubject
    {
        return new NumberSubject('metadata.chain_length', self::class);
    }
}
