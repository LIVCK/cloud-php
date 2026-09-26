<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\Conditions\Condition;
use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\DnsRecordType;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\IpVersion;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Support\Json;
use LIVCK\Cloud\Support\KeepSecret;
use LIVCK\Cloud\Tests\Fixtures\PayloadPath;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;

it('collects only the fields that were set', function (): void {
    expect(UpdateService::make()->isEmpty())->toBeTrue()
        ->and(UpdateService::make()->toArray())->toBe([])
        ->and(UpdateService::make()->withName('Shop')->withTarget('https://shop.example.com')->toArray())->toBe(['name' => 'Shop', 'target' => 'https://shop.example.com'])
        ->and(UpdateService::make()->withRetries(3)->isEmpty())->toBeFalse()
        ->and(UpdateService::make()->withRetries(3)->toArray())->toBe(['settings' => ['retries' => 3]])
        ->and(UpdateService::make()->withIntervalSeconds(30)->withTimeoutSeconds(5)->withProbes('ffm', 'hel', 'ffm')->toArray())
        ->toBe(['settings' => ['interval_seconds' => 30, 'timeout_seconds' => 5, 'assigned_probes' => ['ffm', 'hel']]]);
});

it('sends a partial settings block without an interval, as the server merges per key', function (): void {
    expect(Json::encode(UpdateService::make()->withMethod(HttpMethod::Head)->toArray()))->toBe('{"settings":{"config":{"method":"HEAD"}}}');
});

it('replaces the tag set as a whole and can clear it', function (): void {
    $tag = Tag::fromArray(tagPayload());

    expect(UpdateService::make()->withTags($tag, 'b' . str_repeat('1', 20))->toArray())->toBe(['tags' => [$tag->id, 'b' . str_repeat('1', 20)]])
        ->and(UpdateService::make()->withoutTags()->toArray())->toBe(['tags' => []])
        ->and(fn(): UpdateService => UpdateService::make()->withTags('kunde:4711'))->toThrow(InvalidArgumentException::class, 'not a tag id');
});

it('sends role maps as objects and null to fall back to the organization', function (): void {
    expect(Json::encode(UpdateService::make()->withProbeRoles(['hel' => ProbeRole::Reachability])->toArray()))->toBe('{"settings":{"probe_roles":{"hel":"reachability"}}}')
        ->and(Json::encode(UpdateService::make()->withoutProbeRoles()->toArray()))->toBe('{"settings":{"probe_roles":null}}');
});

it('writes the typed config keys in their wire form', function (): void {
    $payload = UpdateService::make()
        ->withMethod(HttpMethod::Post)
        ->withBody('{}')
        ->withFollowRedirects(false)
        ->withVerifySsl(false)
        ->withIpVersion(IpVersion::Ipv6)
        ->withSmartDualstack()
        ->withDnsRecordType(DnsRecordType::Mx)
        ->withAuth(HttpAuth::apiKey('X-Api-Key', KeepSecret::keep()))
        ->withConditions(HttpCondition::statusCode()->gte(500), Condition::custom('metadata.protocol', 'neq', 'HTTP/2.0', 'degraded'))
        ->toArray();

    expect(Json::encode($payload))->toBe('{"settings":{"config":{'
        . '"method":"POST","body":"{}","follow_redirects":false,"verify_ssl":false,"ip_version":"ipv6","smart_dualstack":true,"dns_type":"MX",'
        . '"auth":{"type":"api_key","header":"X-Api-Key","value":"__LIVCK_KEEP_UNCHANGED__"},'
        . '"conditions":[{"field":"status_code","operator":"gte","value":500,"status":"down"},{"field":"metadata.protocol","operator":"neq","value":"HTTP/2.0","status":"degraded"}]'
        . '}}}');
});

it('resets to the default conditions with an empty list', function (): void {
    expect(UpdateService::make()->withDefaultConditions()->toArray())->toBe(['settings' => ['config' => ['conditions' => []]]]);
});

it('sends a header map as an object, empty included', function (): void {
    expect(Json::encode(UpdateService::make()->withHeaders([])->toArray()))->toBe('{"settings":{"config":{"headers":{}}}}')
        ->and(Json::encode(UpdateService::make()->withHeader('X-Trace', 'on')->withHeader('X-Api-Key', KeepSecret::keep())->toArray()))
        ->toBe('{"settings":{"config":{"headers":{"X-Trace":"on","X-Api-Key":"__LIVCK_KEEP_UNCHANGED__"}}}}');
});

describe('basedOn', function (): void {
    it('prefills the current settings with every secret kept, so one header can change among many', function (): void {
        $service = Service::fromArray(ServiceFixtures::payload());

        $payload = UpdateService::basedOn($service)->withHeader('X-Trace', 'on')->toArray();
        $config = PayloadPath::config($payload);

        expect($payload)->toBe([
            'settings' => [
                'interval_seconds' => 60,
                'timeout_seconds' => 10,
                'retries' => 2,
                'assigned_probes' => ['ffm', 'hel'],
                'probe_roles' => ['hel' => 'reachability'],
                'config' => [
                    'method' => 'GET',
                    'headers' => $config['headers'],
                    'auth' => ['type' => 'bearer', 'token' => KeepSecret::SENTINEL],
                    'body' => '',
                    'follow_redirects' => true,
                    'verify_ssl' => true,
                    'ip_version' => 'auto',
                    'smart_dualstack' => false,
                    'conditions' => [['field' => 'status_code', 'operator' => 'gte', 'value' => 400, 'status' => 'down']],
                ],
            ],
        ]);

        expect(PayloadPath::config(Json::decode(Json::encode($payload)))['headers'])->toBe([
            'X-Api-Key' => KeepSecret::SENTINEL,
            'X-Trace' => 'on',
        ]);
    });

    it('round-trips a service unchanged, secrets as sentinels', function (): void {
        $service = Service::fromArray(ServiceFixtures::payload());

        $sent = Json::decode(Json::encode(UpdateService::basedOn($service)->toArray()));

        expect($sent['settings'])->toBe(ServiceFixtures::settings());
    });

    it('drops a header from the prefilled map', function (): void {
        $service = Service::fromArray(ServiceFixtures::payload());

        $sent = Json::decode(Json::encode(UpdateService::basedOn($service)->withoutHeader('X-Api-Key')->toArray()));

        expect(PayloadPath::config($sent)['headers'])->toBe([]);
    });

    it('leaves inherited locations and roles out so they keep inheriting', function (): void {
        $service = Service::fromArray(ServiceFixtures::payload(['settings' => ServiceFixtures::settings(['assigned_probes' => null, 'probe_roles' => null])]));

        $settings = PayloadPath::settings(UpdateService::basedOn($service)->toArray());

        expect($settings)->not->toHaveKey('assigned_probes')
            ->and($settings)->not->toHaveKey('probe_roles');
    });

    it('refuses a service that is not configured', function (): void {
        $service = Service::fromArray(ServiceFixtures::unconfigured());

        expect(fn(): UpdateService => UpdateService::basedOn($service))->toThrow(InvalidArgumentException::class, 'not configured');
    });
});

it('lets the escape hatches write any key', function (): void {
    expect(UpdateService::make()->withAttribute('priority', 'high')->withSetting('jitter', true)->withConfig('retry_budget', 3)->toArray())
        ->toBe(['priority' => 'high', 'settings' => ['jitter' => true, 'config' => ['retry_budget' => 3]]]);
});

it('is immutable', function (): void {
    $base = UpdateService::make()->withName('Shop');
    $more = $base->withRetries(1);

    expect($base->toArray())->toBe(['name' => 'Shop'])
        ->and($more->toArray())->toBe(['name' => 'Shop', 'settings' => ['retries' => 1]]);
});

it('rejects blank names, targets and header names, and out-of-range numbers', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank name' => [fn(): UpdateService => UpdateService::make()->withName(' '), 'blank'],
    'blank target' => [fn(): UpdateService => UpdateService::make()->withTarget(''), 'blank'],
    'blank header name' => [fn(): UpdateService => UpdateService::make()->withHeader(' ', 'x'), 'blank'],
    'zero interval' => [fn(): UpdateService => UpdateService::make()->withIntervalSeconds(0), 'at least one second'],
    'zero timeout' => [fn(): UpdateService => UpdateService::make()->withTimeoutSeconds(0), 'at least one second'],
    'negative retries' => [fn(): UpdateService => UpdateService::make()->withRetries(-1), 'negative'],
    'no probes' => [fn(): UpdateService => UpdateService::make()->withProbes(), 'at least one'],
    'unrecognized role' => [fn(): UpdateService => UpdateService::make()->withProbeRoles(['ffm' => ProbeRole::Unrecognized]), 'Unrecognized'],
]);
