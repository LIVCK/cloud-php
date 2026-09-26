<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\Conditions\Condition;
use LIVCK\Cloud\Builders\Conditions\DnsCondition;
use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\Conditions\IcmpCondition;
use LIVCK\Cloud\Builders\Conditions\SslCondition;
use LIVCK\Cloud\Builders\Conditions\TcpCondition;
use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Enums\DnsRecordType;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\IpVersion;
use LIVCK\Cloud\Exceptions\CatalogValidationException;
use LIVCK\Cloud\Tests\Fixtures\CatalogFixture;

/**
 * The problems a builder reports against a catalog, or an empty list when it passes.
 *
 * @return list<string>
 */
function catalogProblems(ServiceBuilder $builder, ?CheckTypeCatalog $catalog = null): array
{
    try {
        $builder->validate($catalog ?? CheckTypeCatalog::fromArray(CatalogFixture::data()));
    } catch (CatalogValidationException $e) {
        return $e->problems();
    }

    return [];
}

it('passes a payload that only uses what the catalog offers', function (): void {
    $builder = ServiceBuilder::http('Shop', 'https://shop.example.com')
        ->method(HttpMethod::Post)
        ->headers(['X-Api-Key' => 'k'])
        ->auth(HttpAuth::bearer('t'))
        ->body('{}')
        ->followRedirects(false)
        ->verifySsl()
        ->ipVersion(IpVersion::Ipv4)
        ->smartDualstack()
        ->condition(
            HttpCondition::statusCode()->in([500, 502]),
            HttpCondition::responseTimeMs()->gt(2000)->degraded(),
            HttpCondition::body()->contains('OK'),
            HttpCondition::json('data.status')->eq('ok'),
            HttpCondition::header('content-type')->contains('json'),
            HttpCondition::redirectsFollowed()->gt(3),
            HttpCondition::contentLength()->lt(10),
            HttpCondition::protocol()->neq('HTTP/2.0'),
            HttpCondition::finalUrl()->contains('/login'),
        );

    expect(catalogProblems($builder))->toBe([]);
});

it('passes every typed family against the catalog', function (): void {
    expect(catalogProblems(ServiceBuilder::tcp('Mail', 'mail.example.com', 993)->condition(TcpCondition::resolvedIpCount()->lt(2))))->toBe([])
        ->and(catalogProblems(ServiceBuilder::dns('Zone', 'example.com', DnsRecordType::Mx)->condition(DnsCondition::mxCount()->eq(0), DnsCondition::mxRecords()->contains('mx1'))))->toBe([])
        ->and(catalogProblems(ServiceBuilder::icmp('Gateway', 'gw.example.net')->condition(IcmpCondition::maxRttMs()->gt(250))))->toBe([])
        ->and(catalogProblems(ServiceBuilder::ssl('Cert', 'shop.example.com')->condition(SslCondition::daysUntilExpiry()->lt(14), SslCondition::tlsVersion()->notIn(['TLS 1.3']), SslCondition::dnsNames()->contains('shop.example.com'), SslCondition::chainLength()->gte(2))))->toBe([])
        ->and(catalogProblems(ServiceBuilder::manual('Phone')))->toBe([]);
});

it('reports an unknown config key', function (): void {
    $problems = catalogProblems(ServiceBuilder::http('Shop', 'https://shop.example.com')->config('retry_budget', 3));

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('Unknown config key "retry_budget" for http checks')
        ->and($problems[0])->toContain('method, headers, auth, body, follow_redirects, verify_ssl, ip_version, smart_dualstack');
});

it('reports a value that is not one of a select\'s options', function (): void {
    $problems = catalogProblems(ServiceBuilder::http('Shop', 'https://shop.example.com')->config('method', 'TRACE'));

    expect($problems)->toBe(['"TRACE" is not an option of config.method; the catalog offers "GET", "POST", "PUT", "DELETE", "PATCH", "HEAD", "OPTIONS".']);
});

it('reports a non-boolean for a boolean field', function (): void {
    expect(catalogProblems(ServiceBuilder::http('Shop', 'https://shop.example.com')->config('verify_ssl', 'yes')))
        ->toBe(['config.verify_ssl takes a boolean, string given.']);
});

it('reports an unknown condition field, including one under a parametric prefix with no key', function (): void {
    $problems = catalogProblems(ServiceBuilder::http('Shop', 'https://shop.example.com')->condition(
        Condition::custom('not_a_real_field', 'eq', 1),
        Condition::custom('json.', 'eq', 1),
    ));

    expect($problems)->toHaveCount(2)
        ->and($problems[0])->toContain('Unknown condition field "not_a_real_field" for http checks')
        ->and($problems[1])->toContain('Unknown condition field "json."');
});

it('reports an operator the field does not take', function (): void {
    expect(catalogProblems(ServiceBuilder::http('Shop', 'https://shop.example.com')->condition(Condition::custom('response_time_ms', 'contains', 5, 'degraded'))))
        ->toBe(['Operator "contains" is not valid for the condition field "response_time_ms"; the catalog allows gt, gte, lt, lte.']);
});

it('restricts DNS condition fields to the record type, following the catalog\'s subtype map', function (): void {
    $onNs = catalogProblems(ServiceBuilder::dns('Zone', 'example.com', DnsRecordType::Ns)->condition(DnsCondition::ips()->contains('9')));

    expect($onNs)->toHaveCount(1)
        ->and($onNs[0])->toContain('Unknown condition field "metadata.ips" for dns checks of type NS')
        ->and($onNs[0])->toContain('response_time_ms, metadata.ns_count, metadata.ns_records')
        ->and(catalogProblems(ServiceBuilder::dns('Zone', 'example.com', DnsRecordType::Ns)->condition(DnsCondition::nsCount()->lt(2))))->toBe([])
        // The catalog's default record type (A) applies when the type was set through an override to nothing.
        ->and(catalogProblems(ServiceBuilder::dns('Zone', 'example.com')->condition(DnsCondition::ipCount()->eq(0))))->toBe([]);
});

it('checks the interval against the type\'s own range', function (): void {
    expect(catalogProblems(ServiceBuilder::ssl('Cert', 'shop.example.com')->interval(60)))
        ->toBe(['The interval of 60 seconds lies outside the range of ssl checks (3600 to 604800 seconds).'])
        ->and(catalogProblems(ServiceBuilder::ssl('Cert', 'shop.example.com')->interval(3600)))->toBe([])
        ->and(catalogProblems(ServiceBuilder::http('Shop', 'https://shop.example.com')->interval(1)))->toBe([]);
});

it('validates the rows of a raw conditions override as well', function (): void {
    $problems = catalogProblems(ServiceBuilder::http('Shop', 'https://shop.example.com')->config('conditions', [
        ['field' => 'status_code', 'operator' => 'between', 'value' => [500, 599], 'status' => 'down'],
        'not a row',
    ]));

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('Operator "between" is not valid for the condition field "status_code"');
});

it('lists every problem at once', function (): void {
    $builder = ServiceBuilder::ssl('Cert', 'shop.example.com')
        ->interval(10)
        ->config('port', 8443)
        ->condition(Condition::custom('metadata.issuer', 'gt', 1));

    expect(catalogProblems($builder))->toHaveCount(3);
});

it('refuses a check type the catalog does not offer', function (): void {
    $catalog = CheckTypeCatalog::fromArray(['manual' => CatalogFixture::data()['manual']]);

    expect(fn() => ServiceBuilder::http('Shop', 'https://shop.example.com')->validate($catalog))
        ->toThrow(CatalogValidationException::class, 'no "http" check type; it offers manual');
});

it('passes a field the server adds tomorrow as soon as the catalog lists it', function (): void {
    $data = CatalogFixture::data();
    $http = $data['http'];
    assert(is_array($http) && is_array($http['fields']) && is_array($http['conditions']) && is_array($http['conditions']['fields']));
    $http['fields'][] = ['name' => 'retry_budget', 'type' => 'number', 'label' => 'Retry budget', 'required' => false, 'secret' => false];
    $http['conditions']['fields'][] = ['field' => 'metadata.future_field', 'label' => 'Future', 'type' => 'number', 'operators' => ['between']];
    $data['http'] = $http;

    $builder = ServiceBuilder::http('Shop', 'https://shop.example.com')
        ->config('retry_budget', 3)
        ->condition(Condition::custom('metadata.future_field', 'between', [1, 2]));

    expect(catalogProblems($builder, CheckTypeCatalog::fromArray($data)))->toBe([]);
});

it('formats the exception message from the problems', function (): void {
    $exception = new CatalogValidationException(['first', 'second']);

    expect($exception->getMessage())->toBe("The service payload does not match the check-type catalog:\n - first\n - second")
        ->and($exception->problems())->toBe(['first', 'second']);
});
