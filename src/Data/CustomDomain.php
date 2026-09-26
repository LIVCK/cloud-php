<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Enums\CustomDomainErrorCode;
use LIVCK\Cloud\Enums\CustomDomainStatus;
use LIVCK\Cloud\Support\Field;

/**
 * A hostname of the end customer (`status.example.com`) under which a status page is served.
 *
 * It goes live once two DNS records exist, both listed by {@see dnsRecords()}: the hostname
 * routed to the LIVCK edge, and a TXT record proving control of the domain. The server
 * notices them on its own; `verify()` asks it to look right away.
 */
final readonly class CustomDomain
{
    /**
     * @param string $hostname lower-case, without a trailing dot
     * @param string $cnameTarget where the hostname has to point
     * @param string $txtRecordName where the ownership token has to be published (`_livck-verify.{hostname}`)
     * @param string|null $txtRecordValue the ownership token; null only for domains attached before tokens existed
     * @param bool $verified whether both records were found
     * @param DateTimeImmutable|null $verifiedAt when both records were found
     * @param CustomDomainErrorCode|null $lastErrorCode what the latest check of a pending domain was missing, or
     *                                                  why the server gave up on a failed one; null before the
     *                                                  first check and once verified
     * @param string|null $statuspageId the status page the domain serves
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $id,
        public string $hostname,
        public CustomDomainStatus $status,
        public string $cnameTarget,
        public string $txtRecordName,
        public ?string $txtRecordValue,
        public bool $verified,
        public ?DateTimeImmutable $verifiedAt,
        public ?CustomDomainErrorCode $lastErrorCode,
        public ?string $statuspageId,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $lastErrorCode = Field::nullableString($data, 'last_error_code');

        return new self(
            Field::string($data, 'id'),
            Field::string($data, 'hostname'),
            CustomDomainStatus::fromApi(Field::string($data, 'status')),
            Field::string($data, 'cname_target'),
            Field::string($data, 'txt_record_name'),
            Field::nullableString($data, 'txt_record_value'),
            Field::bool($data, 'verified'),
            Field::nullableInstant($data, 'verified_at'),
            $lastErrorCode === null ? null : CustomDomainErrorCode::fromApi($lastErrorCode),
            Field::nullableString($data, 'statuspage_id'),
            $data,
        );
    }

    /**
     * The records to publish: the CNAME from the hostname to the edge, then the TXT record
     * with the ownership token (left out for the few old domains without a token).
     *
     * The routing check compares resolved addresses, so a zone apex, which cannot carry a
     * CNAME, can use the DNS provider's ALIAS/ANAME or CNAME flattening to the same target.
     *
     * @return list<DnsRecord>
     */
    public function dnsRecords(): array
    {
        $records = [new DnsRecord(DnsRecord::CNAME, $this->hostname, $this->cnameTarget)];

        if ($this->txtRecordValue !== null) {
            $records[] = new DnsRecord(DnsRecord::TXT, $this->txtRecordName, $this->txtRecordValue);
        }

        return $records;
    }

    public function isActive(): bool
    {
        return $this->status === CustomDomainStatus::Active;
    }
}
