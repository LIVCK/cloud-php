<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use LIVCK\Cloud\Builders\ComponentBuilder;
use LIVCK\Cloud\Builders\Conditions\Condition;
use LIVCK\Cloud\Builders\Conditions\DnsCondition;
use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\Conditions\IcmpCondition;
use LIVCK\Cloud\Builders\Conditions\SslCondition;
use LIVCK\Cloud\Builders\Conditions\TcpCondition;
use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Builders\HttpServiceBuilder;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\AccessType;
use LIVCK\Cloud\Enums\AssetType;
use LIVCK\Cloud\Enums\CheckResultStatus;
use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Enums\DnsRecordType;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\IncidentKind;
use LIVCK\Cloud\Enums\IpVersion;
use LIVCK\Cloud\Enums\LogoSize;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Enums\MetricsRange;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Enums\StatusOverride;
use LIVCK\Cloud\Enums\SubscriberChannel;
use LIVCK\Cloud\Payloads\AssetFile;
use LIVCK\Cloud\Payloads\CreateStatuspage;
use LIVCK\Cloud\Payloads\UpdateComponent;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Payloads\UpdateStatuspage;
use LIVCK\Cloud\Payloads\UpdateTag;
use LIVCK\Cloud\Query\CheckQuery;
use LIVCK\Cloud\Query\IncidentQuery;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Query\ServiceIncidentQuery;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Query\StatuspageQuery;
use LIVCK\Cloud\Query\TagQuery;
use LIVCK\Cloud\Support\KeepSecret;
use LIVCK\Cloud\Support\Translatable;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Tests\Fixtures\CatalogFixture;
use LIVCK\Cloud\Tests\Fixtures\CheckFixtures;
use LIVCK\Cloud\Tests\Fixtures\DiscoveryFixtures;
use LIVCK\Cloud\Tests\Fixtures\IncidentFixtures;
use LIVCK\Cloud\Tests\Fixtures\MaintenanceFixtures;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;
use LIVCK\Cloud\Tests\Fixtures\StatuspageFixtures;

/**
 * Every public resource method of the SDK, called once with typical arguments: every
 * ServiceBuilder factory with its options and condition families, the update payloads,
 * the component kinds, the status page payloads, the tag calls, the lookups, and every
 * creating method once more with a caller-supplied Idempotency-Key. The request contract
 * test checks what each call sends; the coverage test checks which operations they reach.
 */
final class SdkCalls
{
    private const string TAG_ID = ServiceFixtures::TAG_ID;

    private const string PAGE_ID = StatuspageFixtures::PAGE_ID;

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $name): SdkCall
    {
        return self::all()[$name] ?? throw new InvalidArgumentException(sprintf('Unknown SDK call "%s".', $name));
    }

    /**
     * @return array<string, SdkCall>
     */
    public static function all(): array
    {
        $calls = [];

        foreach ([
            ...self::discovery(),
            ...self::tags(),
            ...self::services(),
            ...self::serviceCreation(),
            ...self::serviceUpdates(),
            ...self::incidentsAndMaintenances(),
            ...self::statuspages(),
            ...self::components(),
            ...self::customDomains(),
        ] as $call) {
            $calls[$call->name] = $call;
        }

        return $calls;
    }

    /**
     * @return list<SdkCall>
     */
    private static function discovery(): array
    {
        return [
            new SdkCall('me', [MockResponse::json(DiscoveryFixtures::me())], static fn(CloudClient $client): mixed => $client->me()),
            new SdkCall('probes', [MockResponse::json(['data' => DiscoveryFixtures::probes()])], static fn(CloudClient $client): mixed => $client->probes()),
            new SdkCall('checkTypes', [MockResponse::json(CatalogFixture::body())], static fn(CloudClient $client): mixed => $client->checkTypes()),
        ];
    }

    /**
     * @return list<SdkCall>
     */
    private static function tags(): array
    {
        $tag = tagPayload();

        return [
            new SdkCall('tags.list', [MockResponse::page([$tag])], static fn(CloudClient $client): mixed => $client->tags()->list()),
            new SdkCall('tags.list.filtered', [MockResponse::page([$tag])], static fn(CloudClient $client): mixed => $client->tags()->list(
                TagQuery::make()->withLabel('kunde:4711')->withKey('kunde')->withPage(2)->withPerPage(25),
            )),
            new SdkCall('tags.each', [MockResponse::page([$tag])], static fn(CloudClient $client): mixed => iterator_to_array($client->tags()->each(), false)),
            new SdkCall('tags.get', [self::item($tag)], static fn(CloudClient $client): mixed => $client->tags()->get(self::TAG_ID)),
            new SdkCall('tags.findByLabel', [MockResponse::page([$tag])], static fn(CloudClient $client): mixed => $client->tags()->findByLabel('kunde:4711')),
            new SdkCall('tags.ensure', [self::item($tag, 201)], static fn(CloudClient $client): mixed => $client->tags()->ensure('kunde', '4711', '#6366f1')),
            new SdkCall('tags.ensure.keyOnly', [self::item($tag)], static fn(CloudClient $client): mixed => $client->tags()->ensure('kunde')),
            new SdkCall('tags.ensure.withKey', [self::item($tag, 201)], static fn(CloudClient $client): mixed => $client->tags()->ensure('kunde', '4711', idempotencyKey: 'onboard-4711-tag')),
            new SdkCall('tags.create', [self::item($tag, 201)], static fn(CloudClient $client): mixed => $client->tags()->create('kunde', '4711', '#6366f1')),
            new SdkCall('tags.create.withKey', [self::item($tag, 201)], static fn(CloudClient $client): mixed => $client->tags()->create('kunde', '4711', idempotencyKey: 'onboard-4711-tag')),
            new SdkCall('tags.update', [self::item($tag)], static fn(CloudClient $client): mixed => $client->tags()->update(
                self::TAG_ID,
                UpdateTag::make()->withKey('customer')->withValue('4712')->withColor('#22c55e'),
            )),
            new SdkCall('tags.update.dropValue', [self::item($tag)], static fn(CloudClient $client): mixed => $client->tags()->update(self::TAG_ID, UpdateTag::make()->withoutValue())),
            new SdkCall('tags.delete', [MockResponse::noContent()], static function (CloudClient $client): void {
                $client->tags()->delete(self::TAG_ID);
            }),
        ];
    }

    /**
     * @return list<SdkCall>
     */
    private static function services(): array
    {
        $service = ServiceFixtures::payload();
        $id = ServiceFixtures::ID;
        $from = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $to = new DateTimeImmutable('2026-09-26T00:00:00Z');

        return [
            new SdkCall('services.list', [MockResponse::page([$service])], static fn(CloudClient $client): mixed => $client->services()->list()),
            new SdkCall('services.list.byTag', [MockResponse::page([$service])], static fn(CloudClient $client): mixed => $client->services()->list(
                ServiceQuery::make()->withTag('kunde:4711')->withPage(2)->withPerPage(50),
            )),
            new SdkCall('services.list.byTagObject', [MockResponse::page([$service])], static fn(CloudClient $client): mixed => $client->services()->list(
                ServiceQuery::make()->withTag(Tag::fromArray(tagPayload())),
            )),
            new SdkCall('services.each', [MockResponse::page([$service])], static fn(CloudClient $client): mixed => iterator_to_array($client->services()->each(), false)),
            new SdkCall('services.get', [self::item($service)], static fn(CloudClient $client): mixed => $client->services()->get($id)),
            new SdkCall('services.delete', [MockResponse::noContent()], static function (CloudClient $client) use ($id): void {
                $client->services()->delete($id);
            }),
            new SdkCall('services.delete.withOrphans', [MockResponse::noContent()], static function (CloudClient $client) use ($id): void {
                $client->services()->delete($id, true, true);
            }),
            new SdkCall('services.pause', [self::item($service)], static fn(CloudClient $client): mixed => $client->services()->pause($id)),
            new SdkCall('services.resume', [self::item($service)], static fn(CloudClient $client): mixed => $client->services()->resume($id)),
            new SdkCall('services.applyStatusOverride', [self::item($service)], static fn(CloudClient $client): mixed => $client->services()->applyStatusOverride(
                $id,
                StatusOverride::Maintenance,
                'Database migration until 06:00 UTC',
            )),
            new SdkCall('services.removeStatusOverride', [self::item($service)], static fn(CloudClient $client): mixed => $client->services()->removeStatusOverride($id)),
            new SdkCall('services.metrics', [MockResponse::json(ServiceFixtures::metrics('7d'))], static fn(CloudClient $client): mixed => $client->services()->metrics($id, MetricsRange::SevenDays)),
            new SdkCall('services.metrics.default', [MockResponse::json(ServiceFixtures::metrics())], static fn(CloudClient $client): mixed => $client->services()->metrics($id)),
            new SdkCall('services.uptime', [MockResponse::json(ServiceFixtures::uptime())], static fn(CloudClient $client): mixed => $client->services()->uptime($id, 3)),
            new SdkCall('services.responseTimes', [MockResponse::json(ServiceFixtures::responseTimes('1h'))], static fn(CloudClient $client): mixed => $client->services()->responseTimes($id, MetricsRange::OneHour)),
            new SdkCall('services.checks', [MockResponse::cursorPage([CheckFixtures::payload()])], static fn(CloudClient $client): mixed => $client->services()->checks($id)),
            new SdkCall('services.checks.filtered', [MockResponse::cursorPage([CheckFixtures::failed()])], static fn(CloudClient $client): mixed => $client->services()->checks(
                $id,
                CheckQuery::make()
                    ->withProbes('ffm', 'hel')
                    ->withStatuses(CheckResultStatus::Down, CheckResultStatus::Degraded)
                    ->withFrom($from)
                    ->withTo($to)
                    ->withPerPage(100)
                    ->withCursor('MjAyNi0wOS0yMFQxMDowMDowMC4wMDBafGhlbA'),
            )),
            new SdkCall('services.eachCheck', [MockResponse::cursorPage([CheckFixtures::payload()])], static fn(CloudClient $client): mixed => iterator_to_array($client->services()->eachCheck($id), false)),
            new SdkCall('services.incidents', [MockResponse::page([IncidentFixtures::withImpact()])], static fn(CloudClient $client): mixed => $client->services()->incidents($id)),
            new SdkCall('services.incidents.filtered', [MockResponse::page([IncidentFixtures::withImpact()])], static fn(CloudClient $client): mixed => $client->services()->incidents(
                $id,
                ServiceIncidentQuery::make()
                    ->withResolved(false)
                    ->withPublished()
                    ->withFrom($from)
                    ->withTo($to)
                    ->withKind(IncidentKind::Standard)
                    ->withPage(1)
                    ->withPerPage(20),
            )),
            new SdkCall('services.maintenances', [MockResponse::page([MaintenanceFixtures::payload()])], static fn(CloudClient $client): mixed => $client->services()->maintenances($id)),
            new SdkCall('services.maintenances.filtered', [MockResponse::page([MaintenanceFixtures::payload()])], static fn(CloudClient $client): mixed => $client->services()->maintenances(
                $id,
                MaintenanceQuery::make()
                    ->withStatuses(MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress)
                    ->withFrom($from)
                    ->withTo($to)
                    ->withPage(1)
                    ->withPerPage(20),
            )),
        ];
    }

    /**
     * @return list<SdkCall>
     */
    private static function serviceCreation(): array
    {
        $created = self::item(ServiceFixtures::payload(), 201);
        $catalog = self::catalog();

        $create = static fn(ServiceBuilder $builder, bool $validate = true): SdkCall => new SdkCall(
            'services.create.' . $builder->name(),
            [$created],
            static fn(CloudClient $client): mixed => $client->services()->create($builder, $validate ? $catalog : null),
        );

        return [
            $create(self::httpBuilder('http')),
            $create(
                ServiceBuilder::http('http-basic-auth', 'https://portal.example.com/')
                    ->auth(HttpAuth::basic('monitor', 'secret'))
                    ->method(HttpMethod::Head)
                    ->ipVersion(IpVersion::Ipv6)
                    ->condition(HttpCondition::statusCode()->neq(200)),
            ),
            $create(
                ServiceBuilder::http('http-api-key', 'https://api.example.com/health')
                    ->auth(HttpAuth::apiKey('X-Api-Key', 'secret'))
                    ->condition(HttpCondition::statusCode()->in([500, 502, 503])->degraded()),
            ),
            $create(
                ServiceBuilder::http('http-no-auth', 'https://www.example.com/')
                    ->auth(HttpAuth::none())
                    ->headers([])
                    ->condition(HttpCondition::statusCode()->lt(200)),
            ),
            $create(
                ServiceBuilder::http('http-custom-condition', 'https://api.example.com/status')->condition(
                    Condition::custom('status_code', ConditionOperator::NotIn, [200, 204]),
                    Condition::custom('json.data.queue_depth', 'gte', 100, 'degraded'),
                ),
            ),
            $create(self::httpBuilder('http-unvalidated'), false),
            $create(
                ServiceBuilder::tcp('tcp', 'db.example.com', 5432)
                    ->interval(120)
                    ->timeout(5)
                    ->retries(1)
                    ->probes('ffm')
                    ->ipVersion(IpVersion::Ipv6)
                    ->condition(
                        TcpCondition::responseTimeMs()->gt(500)->degraded(),
                        TcpCondition::resolvedIpCount()->lt(2),
                    ),
            ),
            $create(ServiceBuilder::tcp('tcp-ipv6-literal', '2001:db8::25', 25)->smartDualstack()),
            $create(
                ServiceBuilder::dns('dns-a', 'example.com')->condition(
                    DnsCondition::ipCount()->eq(0),
                    DnsCondition::ips()->notContains('203.0.113.9'),
                    DnsCondition::responseTimeMs()->gt(200)->degraded(),
                ),
            ),
            $create(ServiceBuilder::dns('dns-aaaa', 'example.com', DnsRecordType::Aaaa)->condition(DnsCondition::ipCount()->lt(1))),
            $create(
                ServiceBuilder::dns('dns-mx', 'example.com', DnsRecordType::Mx)->condition(
                    DnsCondition::mxCount()->lt(1),
                    DnsCondition::mxRecords()->contains('mx1.example.com.'),
                ),
            ),
            $create(
                ServiceBuilder::dns('dns-ns', 'example.com', DnsRecordType::Ns)->condition(
                    DnsCondition::nsCount()->lt(2)->degraded(),
                    DnsCondition::nsRecords()->contains('ns1.example.net.'),
                ),
            ),
            $create(
                ServiceBuilder::dns('dns-txt', 'example.com', DnsRecordType::Txt)->condition(
                    DnsCondition::txtCount()->eq(0),
                    DnsCondition::txtRecords()->notContains('v=spf1'),
                ),
            ),
            $create(
                ServiceBuilder::dns('dns-cname', 'www.example.com', DnsRecordType::Cname)->condition(
                    DnsCondition::cname()->neq('edge.example.net.'),
                ),
            ),
            $create(
                ServiceBuilder::icmp('icmp', '203.0.113.1')
                    ->retries(1)
                    ->smartDualstack(false)
                    ->condition(
                        IcmpCondition::packetLossPercent()->gt(0)->degraded(),
                        IcmpCondition::maxRttMs()->gt(250),
                        IcmpCondition::packetsReceived()->lt(3),
                        IcmpCondition::responseTimeMs()->gte(100)->degraded(),
                    ),
            ),
            $create(
                ServiceBuilder::ssl('ssl', 'shop.example.com')
                    ->interval(43200)
                    ->ipVersion(IpVersion::Auto)
                    ->condition(
                        SslCondition::daysUntilExpiry()->lt(14)->degraded(),
                        SslCondition::daysUntilExpiry()->lt(3),
                        SslCondition::issuer()->contains("Let's Encrypt"),
                        SslCondition::subject()->eq('shop.example.com'),
                        SslCondition::dnsNames()->contains('www.shop.example.com'),
                        SslCondition::tlsVersion()->in(['TLS 1.0', 'TLS 1.1']),
                        SslCondition::tlsVersion()->notIn(['TLS 1.3'])->degraded(),
                        SslCondition::cipherSuite()->notContains('RC4'),
                        SslCondition::chainLength()->gte(2),
                    ),
            ),
            $create(ServiceBuilder::ssl('ssl-defaults', 'example.com')),
            $create(ServiceBuilder::manual('manual')->tags(self::TAG_ID)),
            new SdkCall('services.create.withKey', [$created], static fn(CloudClient $client): mixed => $client->services()->create(
                ServiceBuilder::http('http-with-key', 'https://shop.example.com/health'),
                $catalog,
                'order-4711-shop',
            )),
        ];
    }

    /**
     * @return list<SdkCall>
     */
    private static function serviceUpdates(): array
    {
        $service = ServiceFixtures::payload();
        $id = ServiceFixtures::ID;

        $update = static fn(string $name, UpdateService $changes): SdkCall => new SdkCall(
            'services.update.' . $name,
            [self::item($service)],
            static fn(CloudClient $client): mixed => $client->services()->update($id, $changes),
        );

        return [
            $update('everything', UpdateService::make()
                ->withName('Shop (EU)')
                ->withTarget('https://shop.example.com/healthz')
                ->withTags(self::TAG_ID)
                ->withIntervalSeconds(60)
                ->withTimeoutSeconds(15)
                ->withRetries(1)
                ->withProbes('ffm', 'hel')
                ->withProbeRoles(['hel' => ProbeRole::Reachability])
                ->withMethod(HttpMethod::Get)
                ->withHeaders(['X-Api-Key' => KeepSecret::keep(), 'X-Trace' => 'sdk'])
                ->withAuth(HttpAuth::basic('monitor', KeepSecret::keep()))
                ->withBody('')
                ->withFollowRedirects()
                ->withVerifySsl()
                ->withIpVersion(IpVersion::Auto)
                ->withSmartDualstack(false)
                ->withConditions(HttpCondition::statusCode()->gte(400), HttpCondition::responseTimeMs()->gt(3000)->degraded())),
            $update('basedOn', UpdateService::basedOn(Service::fromArray($service))
                ->withHeader('X-Trace', 'sdk')
                ->withoutHeader('X-Removed')),
            $update('name', UpdateService::make()->withName('Shop')),
            $update('dropTagsAndRoles', UpdateService::make()->withoutTags()->withoutProbeRoles()),
            $update('dns', UpdateService::make()->withDnsRecordType(DnsRecordType::Aaaa)->withDefaultConditions()),
            $update('bearer', UpdateService::make()->withAuth(HttpAuth::bearer('new-token'))->withHeaders([])),
        ];
    }

    /**
     * @return list<SdkCall>
     */
    private static function incidentsAndMaintenances(): array
    {
        $from = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $to = new DateTimeImmutable('2026-09-26T00:00:00Z');

        return [
            new SdkCall('incidents.list', [MockResponse::page([IncidentFixtures::payload()])], static fn(CloudClient $client): mixed => $client->incidents()->list()),
            new SdkCall('incidents.list.filtered', [MockResponse::page([IncidentFixtures::payload()])], static fn(CloudClient $client): mixed => $client->incidents()->list(
                IncidentQuery::make()
                    ->withServiceIds([ServiceFixtures::ID, Service::fromArray(ServiceFixtures::unconfigured())])
                    ->withResolved()
                    ->withPublished(false)
                    ->withFrom($from)
                    ->withTo($to)
                    ->withKind(IncidentKind::Notice)
                    ->withPage(2)
                    ->withPerPage(50),
            )),
            new SdkCall('incidents.list.emptyScope', [MockResponse::page([])], static fn(CloudClient $client): mixed => $client->incidents()->list(
                IncidentQuery::make()->withServiceIds([]),
            )),
            new SdkCall('incidents.each', [MockResponse::page([IncidentFixtures::payload()])], static fn(CloudClient $client): mixed => iterator_to_array($client->incidents()->each(), false)),
            new SdkCall('incidents.get', [self::item(IncidentFixtures::detailed())], static fn(CloudClient $client): mixed => $client->incidents()->get(IncidentFixtures::ID)),
            new SdkCall('maintenances.list', [MockResponse::page([MaintenanceFixtures::payload()])], static fn(CloudClient $client): mixed => $client->maintenances()->list()),
            new SdkCall('maintenances.list.filtered', [MockResponse::page([MaintenanceFixtures::payload()])], static fn(CloudClient $client): mixed => $client->maintenances()->list(
                MaintenanceQuery::make()
                    ->withServiceIds([ServiceFixtures::ID])
                    ->withStatuses(MaintenanceStatus::Scheduled, MaintenanceStatus::Completed, MaintenanceStatus::Cancelled)
                    ->withFrom($from)
                    ->withTo($to)
                    ->withPage(2)
                    ->withPerPage(50),
            )),
            new SdkCall('maintenances.list.emptyScope', [MockResponse::page([])], static fn(CloudClient $client): mixed => $client->maintenances()->list(
                MaintenanceQuery::make()->withServiceIds([]),
            )),
            new SdkCall('maintenances.each', [MockResponse::page([MaintenanceFixtures::payload()])], static fn(CloudClient $client): mixed => iterator_to_array($client->maintenances()->each(), false)),
            new SdkCall('maintenances.get', [self::item(MaintenanceFixtures::detailed())], static fn(CloudClient $client): mixed => $client->maintenances()->get(MaintenanceFixtures::ID)),
        ];
    }

    /**
     * @return list<SdkCall>
     */
    private static function statuspages(): array
    {
        $page = StatuspageFixtures::page();
        $id = self::PAGE_ID;
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 16);

        $update = static fn(string $name, UpdateStatuspage $changes): SdkCall => new SdkCall(
            'statuspages.update.' . $name,
            [self::item(StatuspageFixtures::customizedPage())],
            static fn(CloudClient $client): mixed => $client->statuspages()->update($id, $changes),
        );

        return [
            new SdkCall('statuspages.list', [MockResponse::page([StatuspageFixtures::listedPage()])], static fn(CloudClient $client): mixed => $client->statuspages()->list()),
            new SdkCall('statuspages.list.paged', [MockResponse::page([StatuspageFixtures::listedPage()])], static fn(CloudClient $client): mixed => $client->statuspages()->list(
                StatuspageQuery::make()->withPage(2)->withPerPage(15),
            )),
            new SdkCall('statuspages.list.bySlug', [MockResponse::page([StatuspageFixtures::listedPage()])], static fn(CloudClient $client): mixed => $client->statuspages()->list(
                StatuspageQuery::make()->withSlug('acme-hosting'),
            )),
            new SdkCall('statuspages.each', [MockResponse::page([StatuspageFixtures::listedPage()])], static fn(CloudClient $client): mixed => iterator_to_array($client->statuspages()->each(), false)),
            new SdkCall('statuspages.get', [self::item($page)], static fn(CloudClient $client): mixed => $client->statuspages()->get($id)),
            new SdkCall('statuspages.findBySlug', [MockResponse::page([StatuspageFixtures::listedPage()])], static fn(CloudClient $client): mixed => $client->statuspages()->findBySlug('acme-hosting')),
            new SdkCall('statuspages.create', [self::item($page, 201)], static fn(CloudClient $client): mixed => $client->statuspages()->create(
                CreateStatuspage::make('Acme Hosting')->withSlug('acme-hosting'),
            )),
            new SdkCall('statuspages.create.withKey', [self::item($page, 201)], static fn(CloudClient $client): mixed => $client->statuspages()->create(
                CreateStatuspage::make('Acme Hosting')->withSlug('acme-hosting'),
                'onboard-4711-statuspage',
            )),
            new SdkCall('statuspages.create.translated', [self::item($page, 201)], static fn(CloudClient $client): mixed => $client->statuspages()->create(
                new CreateStatuspage(Translatable::translations(['de' => 'Acme Status', 'en' => 'Acme status'])),
            )),
            $update('everything', UpdateStatuspage::make()
                ->withName(Translatable::translations(['de' => 'Acme Status', 'en' => 'Acme status']))
                ->withSlug('acme-status')
                ->withPrimaryColor('#0F172A')
                ->withSecondaryColor('#22C55E')
                ->withCustomCss('.logo { height: 40px; }')
                ->withImprintUrl('https://example.com/imprint')
                ->withPrivacyPolicyUrl('mailto:privacy@example.com')
                ->withShowLogo(false)
                ->withLogoSize(LogoSize::Large)
                ->withShowLivi(false)
                ->withShowAffectedServices(false)
                ->withShowUnlinkedServices(true)
                ->withShowIncidentHistory(false)
                ->withStatusJsonIncludesHidden(true)
                ->withAccessType(AccessType::Password)
                ->withPassword('correct horse battery staple')
                ->withEmailWhitelist(['ops@example.com', 'noc@example.com'])
                ->withSubscriberChannels(SubscriberChannel::Email, SubscriberChannel::Webhook, SubscriberChannel::Slack)
                ->withSupportedLocales(['de', 'en'])
                ->withDefaultLocale('en')),
            $update('clear', UpdateStatuspage::make()
                ->withName('Acme Hosting')
                ->withoutPrimaryColor()
                ->withoutSecondaryColor()
                ->withoutCustomCss()
                ->withoutImprintUrl()
                ->withoutPrivacyPolicyUrl()
                ->withAccessType(AccessType::Public)
                ->withOrganizationLocales()),
            $update('whitelist', UpdateStatuspage::make()->withAccessType(AccessType::EmailWhitelist)->withEmailWhitelist(['ops@example.com'])),
            new SdkCall('statuspages.delete', [MockResponse::noContent()], static function (CloudClient $client) use ($id): void {
                $client->statuspages()->delete($id);
            }),
            new SdkCall('statuspages.publish', [self::item($page)], static fn(CloudClient $client): mixed => $client->statuspages()->publish($id)),
            new SdkCall('statuspages.unpublish', [self::item($page)], static fn(CloudClient $client): mixed => $client->statuspages()->unpublish($id)),
            new SdkCall('statuspages.uploadAsset.logo', [self::item(StatuspageFixtures::assetResponsePage())], static fn(CloudClient $client): mixed => $client->statuspages()->uploadAsset(
                $id,
                AssetType::Logo,
                AssetFile::fromContents($png, 'logo.png'),
            )),
            new SdkCall('statuspages.uploadAsset.logoDark', [self::item(StatuspageFixtures::assetResponsePage())], static fn(CloudClient $client): mixed => $client->statuspages()->uploadAsset(
                $id,
                AssetType::LogoDark,
                AssetFile::fromContents('<svg xmlns="http://www.w3.org/2000/svg"/>', 'logo-dark.svg'),
            )),
            new SdkCall('statuspages.uploadAsset.favicon', [self::item(StatuspageFixtures::assetResponsePage())], static fn(CloudClient $client): mixed => $client->statuspages()->uploadAsset(
                $id,
                AssetType::Favicon,
                AssetFile::fromContents($png, 'favicon.png'),
            )),
            new SdkCall('statuspages.deleteAsset', [self::item(StatuspageFixtures::assetResponsePage())], static fn(CloudClient $client): mixed => $client->statuspages()->deleteAsset($id, AssetType::Favicon)),
        ];
    }

    /**
     * @return list<SdkCall>
     */
    private static function components(): array
    {
        $group = StatuspageFixtures::group();
        $component = StatuspageFixtures::component();
        $componentId = StatuspageFixtures::COMPONENT_ID;
        $create = self::componentCreation(...);

        $update = static fn(string $name, UpdateComponent $changes): SdkCall => new SdkCall(
            'components.update.' . $name,
            [self::item($component)],
            static fn(CloudClient $client): mixed => $client->statuspages()->components(self::PAGE_ID)->update($componentId, $changes),
        );

        return [
            new SdkCall('components.all', [MockResponse::json(['data' => [$group, $component]])], static fn(CloudClient $client): mixed => $client->statuspages()->components(self::PAGE_ID)->all()),
            new SdkCall('components.get', [self::item($component)], static fn(CloudClient $client): mixed => $client->statuspages()->components(self::PAGE_ID)->get($componentId)),
            $create('group', ComponentBuilder::group('Infrastructure')
                ->description('Network, power and cooling')
                ->visible()
                ->displayOrder(0)
                ->showUptimeBars()
                ->hideOperationalChildren()
                ->defaultOpen(false), $group),
            $create('syncedGroup', ComponentBuilder::syncedGroup(Translatable::translations(['de' => 'Deine Dienste', 'en' => 'Your services']), Tag::fromArray(tagPayload()))
                ->syncNewVisible(false), StatuspageFixtures::syncedGroup()),
            $create('service', ComponentBuilder::service(Service::fromArray(ServiceFixtures::payload()))
                ->parent(StatuspageFixtures::GROUP_ID)
                ->displayOrder(3), $component),
            $create('serviceById', ComponentBuilder::service(StatuspageFixtures::SERVICE_ID, 'Website')
                ->visible(false), $component),
            $create('manual', ComponentBuilder::manual('Phone support')
                ->description(Translatable::translations(['de' => 'Hotline und Rückrufe', 'en' => 'Hotline and callbacks'])), StatuspageFixtures::component(['service' => null])),
            new SdkCall('components.create.withKey', [self::item($group, 201)], static fn(CloudClient $client): mixed => $client->statuspages()->components(self::PAGE_ID)->create(
                ComponentBuilder::group('Infrastructure'),
                'onboard-4711-group',
            )),
            $update('everything', UpdateComponent::make()
                ->withName('Website')
                ->withDescription('Shop and customer portal')
                ->withService(StatuspageFixtures::SERVICE_ID)
                ->withParent(StatuspageFixtures::GROUP_ID)
                ->withIsGroup(false)
                ->withIsVisible(false)
                ->withDisplayOrder(3)
                ->withShowUptimeBars(true)
                ->withHideOperationalChildren(false)
                ->withDefaultOpen(true)
                ->withSyncTag(self::TAG_ID)
                ->withSyncNewVisible(true)),
            $update('clear', UpdateComponent::make()
                ->withName(Translatable::translations(['de' => 'Webseite', 'en' => 'Website']))
                ->withoutDescription()
                ->withoutService()
                ->withoutParent()
                ->withoutSyncTag()),
            new SdkCall('components.delete', [MockResponse::noContent()], static function (CloudClient $client) use ($componentId): void {
                $client->statuspages()->components(self::PAGE_ID)->delete($componentId);
            }),
        ];
    }

    /**
     * @param array<string, mixed> $response the component the server answers with
     */
    private static function componentCreation(string $name, ComponentBuilder $builder, array $response): SdkCall
    {
        return new SdkCall(
            'components.create.' . $name,
            [self::item($response, 201)],
            static fn(CloudClient $client): mixed => $client->statuspages()->components(self::PAGE_ID)->create($builder),
        );
    }

    /**
     * @return list<SdkCall>
     */
    private static function customDomains(): array
    {
        $domain = StatuspageFixtures::customDomain();
        $domainId = StatuspageFixtures::DOMAIN_ID;

        return [
            new SdkCall('customDomains.all', [MockResponse::json(['data' => [$domain]])], static fn(CloudClient $client): mixed => $client->statuspages()->customDomains(self::PAGE_ID)->all()),
            new SdkCall('customDomains.get', [self::item($domain)], static fn(CloudClient $client): mixed => $client->statuspages()->customDomains(self::PAGE_ID)->get($domainId)),
            new SdkCall('customDomains.attach', [self::item($domain, 201)], static fn(CloudClient $client): mixed => $client->statuspages()->customDomains(self::PAGE_ID)->attach('status.example.com')),
            new SdkCall('customDomains.attach.withKey', [self::item($domain, 201)], static fn(CloudClient $client): mixed => $client->statuspages()->customDomains(self::PAGE_ID)->attach('status.example.com', 'onboard-4711-domain')),
            new SdkCall('customDomains.verify', [self::item(StatuspageFixtures::activeDomain())], static fn(CloudClient $client): mixed => $client->statuspages()->customDomains(self::PAGE_ID)->verify($domainId)),
            new SdkCall('customDomains.detach', [MockResponse::noContent()], static function (CloudClient $client) use ($domainId): void {
                $client->statuspages()->customDomains(self::PAGE_ID)->detach($domainId);
            }),
        ];
    }

    /** An HTTP check with every typed option and condition family set. */
    private static function httpBuilder(string $name): HttpServiceBuilder
    {
        return ServiceBuilder::http($name, 'https://shop.example.com/health')
            ->interval(60)
            ->timeout(10)
            ->retries(2)
            ->probes('ffm', 'hel')
            ->probeRole('hel', ProbeRole::Reachability)
            ->tags(self::TAG_ID, Tag::fromArray(tagPayload(['id' => 'W2TuHYS9a6keIj7CnzU12'])))
            ->method(HttpMethod::Post)
            ->headers(['X-Api-Key' => 'key-one'])
            ->header('Accept', 'application/json')
            ->auth(HttpAuth::bearer('token-one'))
            ->body('{"ping":true}')
            ->followRedirects(false)
            ->verifySsl(false)
            ->ipVersion(IpVersion::Auto)
            ->smartDualstack()
            ->condition(
                HttpCondition::statusCode()->gte(500),
                HttpCondition::statusCode()->in([502, 503])->degraded(),
                HttpCondition::responseTimeMs()->gt(2000)->degraded(),
                HttpCondition::body()->contains('OK'),
                HttpCondition::json('data.status')->eq('ok'),
                HttpCondition::json('items.#')->lte(100)->degraded(),
                HttpCondition::header('content-type')->contains('json'),
                HttpCondition::redirectsFollowed()->lte(2),
                HttpCondition::contentLength()->gt(0),
                HttpCondition::protocol()->eq('HTTP/2.0'),
                HttpCondition::finalUrl()->contains('shop.example.com'),
            );
    }

    private static function catalog(): CheckTypeCatalog
    {
        return CheckTypeCatalog::fromArray(CatalogFixture::data());
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function item(array $data, int $status = 200): MockResponse
    {
        return MockResponse::json(['data' => $data], $status);
    }
}
