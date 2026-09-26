<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\Conditions\Condition;
use LIVCK\Cloud\Builders\Conditions\DnsCondition;
use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\Conditions\IcmpCondition;
use LIVCK\Cloud\Builders\Conditions\SslCondition;
use LIVCK\Cloud\Builders\Conditions\TcpCondition;
use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Builders\HttpServiceBuilder;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\DnsRecordType;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\IpVersion;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Json;
use LIVCK\Cloud\Tests\Fixtures\PayloadPath;

describe('factories', function (): void {
    it('builds an HTTP service with every typed setter', function (): void {
        $tag = Tag::fromArray(tagPayload());

        $payload = ServiceBuilder::http('Shop', ' https://shop.example.com/health ')
            ->interval(30)
            ->timeout(10)
            ->retries(1)
            ->probes('ffm', 'hel', 'ffm')
            ->probeRole('hel', ProbeRole::Reachability)
            ->tags($tag)
            ->method(HttpMethod::Post)
            ->headers(['X-Api-Key' => 'k1', 'Accept' => 'application/json'])
            ->header('X-Trace', 'on')
            ->auth(HttpAuth::basic('monitor', 'pw'))
            ->body('{"ping":true}')
            ->followRedirects(false)
            ->verifySsl(false)
            ->ipVersion(IpVersion::Ipv6)
            ->smartDualstack()
            ->condition(HttpCondition::statusCode()->in([200, 204]))
            ->condition(HttpCondition::json('data.status')->eq('ok')->degraded(), HttpCondition::header('content-type')->contains('json'))
            ->toArray();

        expect(Json::decode(Json::encode($payload)))->toBe([
            'name' => 'Shop',
            'check_type' => 'http',
            'target' => 'https://shop.example.com/health',
            'tags' => [$tag->id],
            'settings' => [
                'interval_seconds' => 30,
                'timeout_seconds' => 10,
                'retries' => 1,
                'assigned_probes' => ['ffm', 'hel'],
                'probe_roles' => ['hel' => 'reachability'],
                'config' => [
                    'method' => 'POST',
                    'headers' => ['X-Api-Key' => 'k1', 'Accept' => 'application/json', 'X-Trace' => 'on'],
                    'auth' => ['type' => 'basic', 'username' => 'monitor', 'password' => 'pw'],
                    'body' => '{"ping":true}',
                    'follow_redirects' => false,
                    'verify_ssl' => false,
                    'ip_version' => 'ipv6',
                    'smart_dualstack' => true,
                    'conditions' => [
                        ['field' => 'status_code', 'operator' => 'in', 'value' => [200, 204], 'status' => 'down'],
                        ['field' => 'json.data.status', 'operator' => 'eq', 'value' => 'ok', 'status' => 'degraded'],
                        ['field' => 'header.content-type', 'operator' => 'contains', 'value' => 'json', 'status' => 'down'],
                    ],
                ],
            ],
        ]);
    });

    it('sends an empty header map as an object, never a list', function (): void {
        expect(Json::encode(ServiceBuilder::http('Shop', 'https://shop.example.com')->headers([])->toArray()))
            ->toBe('{"name":"Shop","check_type":"http","target":"https://shop.example.com","settings":{"interval_seconds":60,"config":{"headers":{}}}}');
    });

    it('builds a TCP service with a host:port target and brackets an IPv6 literal', function (): void {
        expect(ServiceBuilder::tcp('Mail', 'mail.example.com', 993)->condition(TcpCondition::responseTimeMs()->gt(500)->degraded())->toArray())->toBe([
            'name' => 'Mail',
            'check_type' => 'tcp',
            'target' => 'mail.example.com:993',
            'settings' => [
                'interval_seconds' => 60,
                'config' => ['conditions' => [['field' => 'response_time_ms', 'operator' => 'gt', 'value' => 500, 'status' => 'degraded']]],
            ],
        ])
            ->and(ServiceBuilder::tcp('v6', '2001:db8::1', 443)->target())->toBe('[2001:db8::1]:443')
            ->and(ServiceBuilder::tcp('v6', '[2001:db8::1]', 443)->target())->toBe('[2001:db8::1]:443');
    });

    it('builds a DNS service with the record type and record-aware conditions', function (): void {
        expect(ServiceBuilder::dns('Zone', 'example.com', DnsRecordType::Ns)->condition(DnsCondition::nsCount()->lt(2))->toArray())->toBe([
            'name' => 'Zone',
            'check_type' => 'dns',
            'target' => 'example.com',
            'settings' => [
                'interval_seconds' => 60,
                'config' => [
                    'dns_type' => 'NS',
                    'conditions' => [['field' => 'metadata.ns_count', 'operator' => 'lt', 'value' => 2, 'status' => 'down']],
                ],
            ],
        ])
            ->and(ServiceBuilder::dns('Zone', 'example.com')->recordType(DnsRecordType::Aaaa)->toArray()['settings'])->toBe(['interval_seconds' => 60, 'config' => ['dns_type' => 'AAAA']]);
    });

    it('builds an ICMP service', function (): void {
        expect(ServiceBuilder::icmp('Gateway', 'gw.example.net')->ipVersion(IpVersion::Ipv4)->condition(IcmpCondition::packetLossPercent()->gt(10))->toArray())->toBe([
            'name' => 'Gateway',
            'check_type' => 'icmp',
            'target' => 'gw.example.net',
            'settings' => [
                'interval_seconds' => 60,
                'config' => [
                    'ip_version' => 'ipv4',
                    'conditions' => [['field' => 'metadata.packet_loss_pct', 'operator' => 'gt', 'value' => 10, 'status' => 'down']],
                ],
            ],
        ]);
    });

    it('builds an SSL service with the six-hour default interval', function (): void {
        $builder = ServiceBuilder::ssl('Cert', 'shop.example.com')->condition(SslCondition::daysUntilExpiry()->lt(7)->degraded(), SslCondition::tlsVersion()->in(['TLS 1.0']));

        expect($builder->toArray())->toBe([
            'name' => 'Cert',
            'check_type' => 'ssl',
            'target' => 'shop.example.com',
            'settings' => [
                'interval_seconds' => 21600,
                'config' => ['conditions' => [
                    ['field' => 'metadata.days_until_expiry', 'operator' => 'lt', 'value' => 7, 'status' => 'degraded'],
                    ['field' => 'metadata.tls_version', 'operator' => 'in', 'value' => ['TLS 1.0'], 'status' => 'down'],
                ]],
            ],
        ])
            ->and($builder->intervalSeconds())->toBe(21600)
            ->and($builder->interval(3600)->intervalSeconds())->toBe(3600);
    });

    it('builds a manual service without target or settings', function (): void {
        $builder = ServiceBuilder::manual('Phone system')->tags('V1StGXR8Z5jdHi6BmyT01');

        expect($builder->toArray())->toBe(['name' => 'Phone system', 'check_type' => 'manual', 'tags' => ['V1StGXR8Z5jdHi6BmyT01']])
            ->and($builder->checkType())->toBe(CheckType::Manual)
            ->and($builder->target())->toBeNull()
            ->and($builder->intervalSeconds())->toBeNull()
            ->and($builder->name())->toBe('Phone system');
    });

    it('gives a manual service settings only when a caller insists, interval included', function (): void {
        expect(ServiceBuilder::manual('Phone')->setting('note', 'x')->toArray()['settings'])->toBe(['interval_seconds' => 60, 'note' => 'x']);
    });
});

describe('immutability', function (): void {
    it('returns a new instance from every setter and leaves the receiver untouched', function (): void {
        $base = ServiceBuilder::http('Shop', 'https://shop.example.com')->header('A', '1');
        $changed = $base->interval(15)->header('B', '2')->tags('V1StGXR8Z5jdHi6BmyT01')->condition(HttpCondition::body()->contains('OK'))->config('x', 1)->setting('y', 2)->attribute('z', 3);

        expect($changed)->not->toBe($base)
            ->and($changed)->toBeInstanceOf(HttpServiceBuilder::class)
            ->and(Json::decode(Json::encode($base->toArray())))->toBe([
                'name' => 'Shop',
                'check_type' => 'http',
                'target' => 'https://shop.example.com',
                'settings' => ['interval_seconds' => 60, 'config' => ['headers' => ['A' => '1']]],
            ])
            ->and(PayloadPath::config(Json::decode(Json::encode($changed->toArray())))['headers'])->toBe(['A' => '1', 'B' => '2'])
            ->and($changed->toArray()['z'])->toBe(3);
    });
});

describe('escape hatches', function (): void {
    it('override typed values and are sent as given', function (): void {
        $payload = ServiceBuilder::http('Shop', 'https://shop.example.com')
            ->method(HttpMethod::Get)
            ->interval(60)
            ->config('method', 'TRACE')
            ->config('retry_budget', 3)
            ->setting('interval_seconds', 15)
            ->setting('jitter', true)
            ->attribute('name', 'Overridden')
            ->attribute('priority', 'high')
            ->toArray();

        expect($payload)->toBe([
            'name' => 'Overridden',
            'check_type' => 'http',
            'target' => 'https://shop.example.com',
            'settings' => [
                'interval_seconds' => 15,
                'config' => ['method' => 'TRACE', 'retry_budget' => 3],
                'jitter' => true,
            ],
            'priority' => 'high',
        ]);
    });

    it('lets a raw conditions override replace the typed conditions', function (): void {
        $payload = ServiceBuilder::http('Shop', 'https://shop.example.com')
            ->condition(HttpCondition::statusCode()->gte(500))
            ->config('conditions', [['field' => 'status_code', 'operator' => 'eq', 'value' => 418, 'status' => 'degraded']])
            ->toArray();

        expect(PayloadPath::config($payload)['conditions'])->toBe([['field' => 'status_code', 'operator' => 'eq', 'value' => 418, 'status' => 'degraded']]);
    });

    it('accepts a custom condition on every builder', function (): void {
        $custom = Condition::custom('metadata.future_field', 'gte', 1, 'degraded');

        expect(PayloadPath::config(ServiceBuilder::tcp('Mail', 'mail.example.com', 25)->condition($custom)->toArray())['conditions'])
            ->toBe([['field' => 'metadata.future_field', 'operator' => 'gte', 'value' => 1, 'status' => 'degraded']]);
    });
});

describe('guards', function (): void {
    it('refuses what can never be right', function (Closure $build, string $message): void {
        expect($build)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'blank name' => [fn(): ServiceBuilder => ServiceBuilder::http(' ', 'https://x'), 'name must not be blank'],
        'blank url' => [fn(): ServiceBuilder => ServiceBuilder::http('Shop', ''), 'URL must not be blank'],
        'port zero' => [fn(): ServiceBuilder => ServiceBuilder::tcp('Mail', 'mail.example.com', 0), 'between 1 and 65535'],
        'port too high' => [fn(): ServiceBuilder => ServiceBuilder::tcp('Mail', 'mail.example.com', 65536), 'between 1 and 65535'],
        'blank host' => [fn(): ServiceBuilder => ServiceBuilder::icmp('Gateway', ' '), 'host must not be blank'],
        'label instead of tag id' => [fn(): ServiceBuilder => ServiceBuilder::manual('Phone')->tags('kunde:4711'), 'not a tag id'],
        'zero interval' => [fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://x')->interval(0), 'at least one second'],
        'zero timeout' => [fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://x')->timeout(0), 'at least one second'],
        'negative retries' => [fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://x')->retries(-1), 'negative'],
        'no probe codes' => [fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://x')->probes(), 'at least one location code'],
        'blank probe role code' => [fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://x')->probeRole('', ProbeRole::Full), 'must not be blank'],
        'unrecognized method' => [fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://x')->method(HttpMethod::Unrecognized), 'Unrecognized'],
        'unrecognized record type' => [fn(): ServiceBuilder => ServiceBuilder::dns('Zone', 'example.com', DnsRecordType::Unrecognized), 'Unrecognized'],
        'bad header name' => [fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://x')->header('X Api Key', 'v'), 'not a valid header name'],
        'header value with a line break' => [fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://x')->header('X-Api-Key', "v\r\n"), 'line breaks'],
        'blank config key' => [fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://x')->config(' ', 1), 'key must not be blank'],
    ]);
});
