<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\Conditions\DnsCondition;
use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\Conditions\IcmpCondition;
use LIVCK\Cloud\Builders\Conditions\SslCondition;
use LIVCK\Cloud\Builders\Conditions\TcpCondition;
use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\CheckTypeDefinition;
use LIVCK\Cloud\Data\Probe;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\DnsRecordType;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\IpVersion;
use LIVCK\Cloud\Enums\TokenType;
use LIVCK\Cloud\Exceptions\CatalogValidationException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 1, discovery: the calling token, the monitoring locations and the check-type
 * catalog, and that every creatable type has a builder the catalog accepts. Opt-in, see
 * LiveApi; creates nothing.
 */
$skip = LiveApi::skipReason(LiveApi::TOKEN_VARIABLE, LiveApi::READONLY_TOKEN_VARIABLE);

describe('scenario 1: discovery', function () use ($skip): void {
    it('describes the token, the locations and the check types', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $journey->note(sprintf('base URI %s, run %s', LiveApi::baseUri(), Scenario::run()));

        try {
            $journey->step('1 me(): organization, abilities, rate limit', function () use ($client, $journey): void {
                $me = DtoAudit::inspect($client->me(), 'me');
                $abilities = [
                    'services.view', 'services.create', 'services.edit', 'services.delete',
                    'incidents.view', 'incidents.create', 'incidents.edit', 'incidents.resolve', 'incidents.delete',
                    'statuspages.view', 'statuspages.create', 'statuspages.edit', 'statuspages.delete', 'statuspages.publish',
                    'maintenances.view', 'maintenances.create', 'maintenances.edit', 'maintenances.delete',
                ];

                expect($me->type)->toBe(TokenType::User)
                    ->and($me->isManaged())->toBeFalse()
                    ->and($me->service)->toBeNull()
                    ->and($me->expires())->toBeFalse()
                    ->and($me->organization->publicId)->not->toBe('')
                    ->and($me->organization->name)->not->toBe('')
                    ->and($me->rateLimit->requestsPerMinute)->toBe(120)
                    ->and(array_values(array_diff($abilities, $me->permissions)))->toBe([]);

                $journey->note(sprintf(
                    'organization "%s" (%s), %d abilities, %d requests per minute',
                    $me->organization->name,
                    $me->organization->publicId,
                    count($me->permissions),
                    $me->rateLimit->requestsPerMinute,
                ));
            });

            $journey->step('2 me() with the read-only token: services.view alone', function (): void {
                $me = DtoAudit::inspect(LiveApi::client(LiveApi::READONLY_TOKEN_VARIABLE)->me(), 'me (read-only token)');

                expect($me->permissions)->toBe(['services.view'])
                    ->and($me->can('services.view'))->toBeTrue()
                    ->and($me->can('services.create'))->toBeFalse();
            });

            $journey->step('3 probes(): the monitoring locations', function () use ($client, $journey): void {
                $probes = DtoAudit::inspect($client->probes(), 'probes');
                $codes = array_map(static fn(Probe $probe): string => $probe->code, $probes);

                expect($codes)->toContain('ffm')->toContain('hel')->toContain('nbg')
                    ->and(array_unique($codes))->toHaveCount(count($codes));

                foreach ($probes as $probe) {
                    expect($probe->name)->not->toBe('');
                }

                $journey->note('locations: ' . implode(', ', $codes));
            });

            $journey->step('4 checkTypes(): every creatable type has a builder the catalog accepts', function () use ($client, $journey): void {
                $catalog = DtoAudit::inspect($client->checkTypes(), 'checkTypes');

                foreach ([CheckType::Http, CheckType::Tcp, CheckType::Dns, CheckType::Icmp, CheckType::Ssl, CheckType::Manual] as $type) {
                    expect($catalog->has($type))->toBeTrue($type->value)
                        ->and($catalog->find($type))->toBeInstanceOf(CheckTypeDefinition::class)
                        ->and($catalog->type($type)->checkType())->toBe($type);
                }

                // Every option and every condition family of every builder, held against the live catalog.
                $builders = [
                    ServiceBuilder::http('a', 'https://example.com/')
                        ->method(HttpMethod::Head)
                        ->header('X-Trace', '1')
                        ->auth(HttpAuth::bearer('t'))
                        ->body('{}')
                        ->followRedirects(false)
                        ->verifySsl(false)
                        ->ipVersion(IpVersion::Ipv4)
                        ->smartDualstack(false)
                        ->condition(
                            HttpCondition::statusCode()->gte(500),
                            HttpCondition::responseTimeMs()->gt(2000)->degraded(),
                            HttpCondition::body()->contains('ok'),
                            HttpCondition::json('data.status')->neq('ok'),
                            HttpCondition::header('content-type')->contains('json'),
                            HttpCondition::redirectsFollowed()->gt(0),
                            HttpCondition::contentLength()->lt(1),
                            HttpCondition::protocol()->contains('HTTP/1.0'),
                            HttpCondition::finalUrl()->notContains('example'),
                        ),
                    ServiceBuilder::tcp('b', 'example.com', 443)
                        ->ipVersion(IpVersion::Auto)
                        ->condition(TcpCondition::responseTimeMs()->gt(1000), TcpCondition::resolvedIpCount()->lt(1)),
                    ServiceBuilder::dns('c', 'example.com', DnsRecordType::Ns)
                        ->condition(DnsCondition::nsCount()->lt(1), DnsCondition::nsRecords()->notContains('iana'), DnsCondition::responseTimeMs()->gt(500)),
                    ServiceBuilder::dns('d', 'example.com', DnsRecordType::Mx)
                        ->condition(DnsCondition::mxCount()->lt(1), DnsCondition::mxRecords()->contains('x')),
                    ServiceBuilder::dns('e', 'example.com', DnsRecordType::Txt)
                        ->condition(DnsCondition::txtCount()->lt(1), DnsCondition::txtRecords()->contains('v=spf1')),
                    ServiceBuilder::dns('f', 'www.example.com', DnsRecordType::Cname)
                        ->condition(DnsCondition::cname()->neq('example.com')),
                    ServiceBuilder::dns('g', 'example.com', DnsRecordType::Aaaa)
                        ->condition(DnsCondition::ipCount()->eq(0), DnsCondition::ips()->contains('::1')),
                    ServiceBuilder::icmp('h', '1.1.1.1')
                        ->condition(IcmpCondition::packetLossPercent()->gt(50), IcmpCondition::maxRttMs()->gt(500), IcmpCondition::packetsReceived()->lt(1), IcmpCondition::responseTimeMs()->gt(500)),
                    ServiceBuilder::ssl('i', 'example.com')
                        ->condition(
                            SslCondition::daysUntilExpiry()->lt(7),
                            SslCondition::issuer()->contains('x'),
                            SslCondition::subject()->neq('x'),
                            SslCondition::dnsNames()->notContains('x'),
                            SslCondition::tlsVersion()->in(['TLS 1.0']),
                            SslCondition::cipherSuite()->contains('RC4'),
                            SslCondition::chainLength()->lt(1),
                        ),
                    ServiceBuilder::manual('j'),
                ];

                foreach ($builders as $builder) {
                    $builder->validate($catalog);
                }

                // And the catalog refuses what a type cannot produce: an NS lookup has no addresses,
                // HTTP checks have no `nope` option, and there is no `heartbeat` type to create.
                expect(static fn() => ServiceBuilder::dns('x', 'example.com', DnsRecordType::Ns)->condition(DnsCondition::ipCount()->eq(0))->validate($catalog))
                    ->toThrow(CatalogValidationException::class);
                expect(static fn() => ServiceBuilder::http('x', 'https://example.com/')->config('nope', true)->validate($catalog))
                    ->toThrow(CatalogValidationException::class);
                expect(static fn(): CheckTypeDefinition => $catalog->type('heartbeat'))
                    ->toThrow(InvalidArgumentException::class);

                $http = $catalog->type(CheckType::Http);
                $ssl = $catalog->type(CheckType::Ssl);

                expect($ssl->interval?->min)->toBe(3600)
                    ->and($ssl->interval?->default)->toBe(21600)
                    ->and($ssl->interval?->allows(1800))->toBeFalse()
                    ->and($catalog->type(CheckType::Manual)->targetRequired)->toBeFalse()
                    ->and($catalog->type(CheckType::Manual)->fields)->toBe([])
                    ->and($http->targetRequired)->toBeTrue()
                    ->and($http->field('method')?->isSelect())->toBeTrue()
                    ->and($http->field('method')?->allowsOption(HttpMethod::Head))->toBeTrue()
                    ->and($http->field('verify_ssl')?->isBoolean())->toBeTrue()
                    ->and($http->hasField('auth'))->toBeTrue()
                    ->and($http->conditions->resolve('json.data.status')?->field)->toBe('json')
                    ->and($http->conditions->field('status_code')?->allows('gte'))->toBeTrue()
                    ->and($http->conditions->field('body')?->allows('gte'))->toBeFalse()
                    ->and($catalog->type(CheckType::Dns)->conditions->bySubtype?->subtypes())->toContain('NS');

                $journey->note(sprintf('check types: %s', implode(', ', $catalog->keys())));
            });
        } finally {
            Scenario::report($journey);
        }
    })->skip($skip !== null, $skip ?? '');
});
