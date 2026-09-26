<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\CustomDomain;
use LIVCK\Cloud\Data\DnsRecord;
use LIVCK\Cloud\Enums\CustomDomainStatus;
use LIVCK\Cloud\Tests\Fixtures\StatuspageFixtures;

it('lists the CNAME and the TXT record to publish', function (): void {
    [$cname, $txt] = CustomDomain::fromArray(StatuspageFixtures::customDomain())->dnsRecords();

    expect($cname->type)->toBe('CNAME')
        ->and($cname->isCname())->toBeTrue()
        ->and($cname->isTxt())->toBeFalse()
        ->and($cname->name)->toBe('status.example.com')
        ->and($cname->value)->toBe('edge.livck-status.com')
        ->and($txt->type)->toBe(DnsRecord::TXT)
        ->and($txt->isTxt())->toBeTrue()
        ->and($txt->name)->toBe('_livck-verify.status.example.com')
        ->and($txt->value)->toBe(StatuspageFixtures::TXT_TOKEN);
});

it('leaves out the TXT record of a domain attached before tokens existed', function (): void {
    $domain = CustomDomain::fromArray(StatuspageFixtures::activeDomain(['txt_record_value' => null]));

    expect($domain->txtRecordValue)->toBeNull()
        ->and($domain->dnsRecords())->toEqual([new DnsRecord(DnsRecord::CNAME, 'status.example.com', 'edge.livck-status.com')]);
});

it('maps every status the server knows', function (string $wire, CustomDomainStatus $status): void {
    expect(CustomDomain::fromArray(StatuspageFixtures::customDomain(['status' => $wire]))->status)->toBe($status);
})->with([
    ['pending_verification', CustomDomainStatus::PendingVerification],
    ['active', CustomDomainStatus::Active],
    ['failed', CustomDomainStatus::Failed],
    ['removing', CustomDomainStatus::Removing],
    ['removed', CustomDomainStatus::Removed],
]);
