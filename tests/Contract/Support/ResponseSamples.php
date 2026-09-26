<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

use InvalidArgumentException;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Tests\Fixtures\CatalogFixture;
use LIVCK\Cloud\Tests\Fixtures\CheckFixtures;
use LIVCK\Cloud\Tests\Fixtures\DiscoveryFixtures;
use LIVCK\Cloud\Tests\Fixtures\IncidentFixtures;
use LIVCK\Cloud\Tests\Fixtures\MaintenanceFixtures;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;
use LIVCK\Cloud\Tests\Fixtures\StatuspageFixtures;

/**
 * Every response body the unit tests feed the SDK, each tied to the operation and status
 * it stands for: the fixtures of tests/Fixtures, `tagPayload()` and the page and cursor
 * envelopes of {@see MockResponse}. A body that does not validate against the document
 * is either a wrong fixture or a DTO built on a wrong assumption.
 */
final class ResponseSamples
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $name): ResponseSample
    {
        return self::all()[$name] ?? throw new InvalidArgumentException(sprintf('Unknown response sample "%s".', $name));
    }

    /**
     * @return array<string, ResponseSample>
     */
    public static function all(): array
    {
        $samples = [];

        foreach ([...self::discovery(), ...self::tags(), ...self::services(), ...self::incidentsAndMaintenances(), ...self::statuspages()] as $sample) {
            $samples[$sample->name] = $sample;
        }

        return $samples;
    }

    /**
     * @return list<ResponseSample>
     */
    private static function discovery(): array
    {
        return [
            self::raw('me', 'GET /me', MockResponse::json(DiscoveryFixtures::me())),
            self::raw('me.agent-token', 'GET /me', MockResponse::json(DiscoveryFixtures::me([
                'type' => 'agent',
                'service' => ['public_id' => ServiceFixtures::ID, 'name' => 'API health'],
                'expires_at' => '2026-12-31T23:59:59+00:00',
            ]))),
            self::collection('probes', 'GET /probes', DiscoveryFixtures::probes()),
            self::raw('meta.check-types', 'GET /meta/check-types', MockResponse::json(CatalogFixture::body())),
        ];
    }

    /**
     * @return list<ResponseSample>
     */
    private static function tags(): array
    {
        return [
            self::item('tags.show', 'GET /tags/{tag}', tagPayload()),
            self::page('tags.index', 'GET /tags', [
                tagPayload(),
                tagPayload(['id' => 'W2TuHYS9a6keIj7CnzU12', 'value' => null, 'label' => 'kunde', 'services_count' => 0]),
            ]),
            self::page('tags.index.empty', 'GET /tags', []),
            self::item('tags.store', 'POST /tags', tagPayload(), 201),
            self::item('tags.ensure.created', 'POST /tags/ensure', tagPayload(), 201),
            self::item('tags.ensure.existing', 'POST /tags/ensure', tagPayload()),
            self::item('tags.update', 'PUT /tags/{tag}', tagPayload(['value' => '4712'])),
        ];
    }

    /**
     * @return list<ResponseSample>
     */
    private static function services(): array
    {
        $service = ServiceFixtures::payload();

        return [
            self::page('services.index', 'GET /services', [$service, ServiceFixtures::unconfigured()]),
            self::item('services.show', 'GET /services/{service}', $service),
            self::item('services.show.unconfigured', 'GET /services/{service}', ServiceFixtures::unconfigured()),
            self::item('services.store', 'POST /services', $service, 201),
            self::item('services.update', 'PUT /services/{service}', $service),
            self::item('services.pause', 'POST /services/{service}/pause', ServiceFixtures::payload(['is_paused' => true, 'paused_reason' => 'manual'])),
            self::item('services.resume', 'POST /services/{service}/resume', $service),
            self::item('services.status-override.apply', 'POST /services/{service}/status-override', ServiceFixtures::payload([
                'effective_status' => 'maintenance',
                'status_override' => 'maintenance',
                'status_override_reason' => 'Migration window',
                'status_override_at' => '2026-09-26T05:00:00+00:00',
            ])),
            self::item('services.status-override.remove', 'DELETE /services/{service}/status-override', $service),
            self::raw('services.metrics', 'GET /services/{service}/metrics', MockResponse::json(ServiceFixtures::metrics())),
            self::raw('services.uptime', 'GET /services/{service}/uptime', MockResponse::json(ServiceFixtures::uptime())),
            self::raw('services.response-times', 'GET /services/{service}/response-times', MockResponse::json(ServiceFixtures::responseTimes())),
            self::raw('services.checks.index', 'GET /services/{service}/checks', MockResponse::cursorPage([CheckFixtures::payload(), CheckFixtures::failed()], 'MjAyNi0wOS0yMFQxMDowMDowMC4wMDBafGhlbA')),
            self::raw('services.checks.index.last-page', 'GET /services/{service}/checks', MockResponse::cursorPage([])),
            self::page('services.incidents.index', 'GET /services/{service}/incidents', [IncidentFixtures::withImpact()]),
            self::page('services.maintenances.index', 'GET /services/{service}/maintenances', [MaintenanceFixtures::payload()]),
        ];
    }

    /**
     * @return list<ResponseSample>
     */
    private static function incidentsAndMaintenances(): array
    {
        return [
            self::page('incidents.index', 'GET /incidents', [IncidentFixtures::payload()]),
            self::item('incidents.show', 'GET /incidents/{incident}', IncidentFixtures::detailed()),
            self::page('maintenances.index', 'GET /maintenances', [MaintenanceFixtures::payload()]),
            self::item('maintenances.show', 'GET /maintenances/{maintenance}', MaintenanceFixtures::detailed()),
        ];
    }

    /**
     * @return list<ResponseSample>
     */
    private static function statuspages(): array
    {
        $components = 'GET /statuspages/{statuspage}/components';
        $domains = 'GET /statuspages/{statuspage}/custom-domains';

        return [
            self::page('statuspages.index', 'GET /statuspages', [StatuspageFixtures::listedPage()]),
            self::item('statuspages.show', 'GET /statuspages/{statuspage}', StatuspageFixtures::page()),
            self::item('statuspages.show.customized', 'GET /statuspages/{statuspage}', StatuspageFixtures::customizedPage()),
            self::item('statuspages.store', 'POST /statuspages', StatuspageFixtures::page(), 201),
            self::item('statuspages.update', 'PUT /statuspages/{statuspage}', StatuspageFixtures::customizedPage()),
            self::item('statuspages.publish', 'POST /statuspages/{statuspage}/publish', StatuspageFixtures::page()),
            self::item('statuspages.unpublish', 'POST /statuspages/{statuspage}/unpublish', StatuspageFixtures::page(['is_published' => false])),
            self::item('statuspages.assets.upload', 'POST /statuspages/{statuspage}/assets/{asset}', StatuspageFixtures::assetResponsePage([
                'logo_url' => 'https://cdn.example.com/media/1/conversions/logo-optimized.png',
            ])),
            self::item('statuspages.assets.destroy', 'DELETE /statuspages/{statuspage}/assets/{asset}', StatuspageFixtures::assetResponsePage()),
            self::collection('statuspages.components.index', $components, [
                StatuspageFixtures::group(),
                StatuspageFixtures::component(),
                StatuspageFixtures::syncedGroup(),
                StatuspageFixtures::syncManagedChild(),
            ]),
            self::item('statuspages.components.show', $components . '/{component}', StatuspageFixtures::component()),
            self::item('statuspages.components.store', 'POST /statuspages/{statuspage}/components', StatuspageFixtures::group(), 201),
            self::item('statuspages.components.update', 'PUT /statuspages/{statuspage}/components/{component}', StatuspageFixtures::syncedGroup()),
            self::collection('statuspages.custom-domains.index', $domains, [StatuspageFixtures::customDomain(), StatuspageFixtures::activeDomain()]),
            self::item('statuspages.custom-domains.show', $domains . '/{domain}', StatuspageFixtures::activeDomain()),
            self::item('statuspages.custom-domains.store', 'POST /statuspages/{statuspage}/custom-domains', StatuspageFixtures::customDomain(), 201),
            self::item('statuspages.custom-domains.verify', 'POST /statuspages/{statuspage}/custom-domains/{domain}/verify', StatuspageFixtures::activeDomain()),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function item(string $name, string $operation, array $data, int $status = 200): ResponseSample
    {
        return new ResponseSample($name, $operation, $status, MockResponse::json(['data' => $data], $status));
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function collection(string $name, string $operation, array $items): ResponseSample
    {
        return new ResponseSample($name, $operation, 200, MockResponse::json(['data' => $items]));
    }

    /**
     * The second of three pages, so that every link of the envelope carries a value.
     *
     * @param list<array<string, mixed>> $items
     */
    private static function page(string $name, string $operation, array $items): ResponseSample
    {
        return new ResponseSample($name, $operation, 200, $items === []
            ? MockResponse::page([])
            : MockResponse::page($items, currentPage: 2, lastPage: 3, perPage: count($items)));
    }

    private static function raw(string $name, string $operation, MockResponse $response): ResponseSample
    {
        return new ResponseSample($name, $operation, $response->status, $response);
    }
}
