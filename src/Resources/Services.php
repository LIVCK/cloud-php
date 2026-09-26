<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\CheckResult;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\Incident;
use LIVCK\Cloud\Data\Maintenance;
use LIVCK\Cloud\Data\ResponseTimePoint;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\ServiceMetrics;
use LIVCK\Cloud\Data\UptimeDay;
use LIVCK\Cloud\Enums\MetricsRange;
use LIVCK\Cloud\Enums\StatusOverride;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Transport;
use LIVCK\Cloud\Pagination\CursorPage;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Query\CheckQuery;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Query\ServiceIncidentQuery;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Support\Envelope;
use LIVCK\Cloud\Support\Field;
use LIVCK\Cloud\Support\Path;

/**
 * `/v1/services` and everything beneath it.
 */
final readonly class Services implements ServicesInterface
{
    public const int MAX_UPTIME_DAYS = 90;

    /** The server's cap on the override reason. */
    public const int MAX_OVERRIDE_REASON_LENGTH = 500;

    public function __construct(
        private Transport $transport,
    ) {}

    public function list(?ServiceQuery $query = null): Page
    {
        $query ??= ServiceQuery::make();
        $response = $this->transport->send(Request::get('services', $query->toArray()));

        return Envelope::page(
            $response,
            Service::fromArray(...),
            fn(int $page): Page => $this->list($query->withPage($page)),
        );
    }

    public function each(?ServiceQuery $query = null): Generator
    {
        return $this->list($query)->lazy();
    }

    public function get(string $id): Service
    {
        $response = $this->transport->send(Request::get(Path::join('services', $id)));

        return Envelope::item($response, Service::fromArray(...));
    }

    public function create(ServiceBuilder $builder, ?CheckTypeCatalog $validateAgainst = null, ?string $idempotencyKey = null): Service
    {
        if ($validateAgainst instanceof CheckTypeCatalog) {
            $builder->validate($validateAgainst);
        }

        $response = $this->transport->send(Request::post('services', $builder->toArray(), $idempotencyKey));

        return Envelope::item($response, Service::fromArray(...));
    }

    public function update(string $id, UpdateService $changes): Service
    {
        if ($changes->isEmpty()) {
            throw new InvalidArgumentException('UpdateService carries no changes; set a name, target, tags or settings first.');
        }

        $response = $this->transport->send(Request::patch(Path::join('services', $id), $changes->toArray()));

        return Envelope::item($response, Service::fromArray(...));
    }

    public function delete(string $id, bool $deleteOrphanedIncidents = false, bool $deleteOrphanedMaintenances = false): void
    {
        $query = [];

        if ($deleteOrphanedIncidents) {
            $query['delete_orphaned_incidents'] = true;
        }

        if ($deleteOrphanedMaintenances) {
            $query['delete_orphaned_maintenances'] = true;
        }

        $this->transport->send(Request::delete(Path::join('services', $id), $query));
    }

    public function pause(string $id): Service
    {
        $response = $this->transport->send(Request::post(Path::join('services', $id, 'pause')));

        return Envelope::item($response, Service::fromArray(...));
    }

    public function resume(string $id): Service
    {
        $response = $this->transport->send(Request::post(Path::join('services', $id, 'resume')));

        return Envelope::item($response, Service::fromArray(...));
    }

    public function applyStatusOverride(string $id, StatusOverride $status, string $reason): Service
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A status override needs a reason; it is shown to your team, never published.');
        }

        if (mb_strlen($reason) > self::MAX_OVERRIDE_REASON_LENGTH) {
            throw new InvalidArgumentException(sprintf('A status override reason has at most %d characters.', self::MAX_OVERRIDE_REASON_LENGTH));
        }

        $response = $this->transport->send(Request::post(
            Path::join('services', $id, 'status-override'),
            ['status' => $status, 'reason' => $reason],
        ));

        return Envelope::item($response, Service::fromArray(...));
    }

    public function removeStatusOverride(string $id): Service
    {
        $response = $this->transport->send(Request::delete(Path::join('services', $id, 'status-override')));

        // Removing an override answers 200 however often it runs: a 404, even on a retry, means the service is gone.
        if ($response->status() === 404) {
            throw ApiException::fromResponse($response);
        }

        return Envelope::item($response, Service::fromArray(...));
    }

    public function metrics(string $id, MetricsRange $range = MetricsRange::TwentyFourHours): ServiceMetrics
    {
        $response = $this->transport->send(Request::get(Path::join('services', $id, 'metrics'), ['range' => $range]));
        $payload = $response->json();
        $meta = Field::object($payload, 'meta');

        return ServiceMetrics::fromArray(
            Field::object($payload, 'data'),
            MetricsRange::fromApi(Field::string($meta, 'range')),
        );
    }

    public function uptime(string $id, int $days = 30): array
    {
        if ($days < 1 || $days > self::MAX_UPTIME_DAYS) {
            throw new InvalidArgumentException(sprintf('days must be between 1 and %d.', self::MAX_UPTIME_DAYS));
        }

        $response = $this->transport->send(Request::get(Path::join('services', $id, 'uptime'), ['days' => $days]));

        return Envelope::collection($response, UptimeDay::fromArray(...));
    }

    public function responseTimes(string $id, MetricsRange $range = MetricsRange::TwentyFourHours): array
    {
        $response = $this->transport->send(Request::get(Path::join('services', $id, 'response-times'), ['range' => $range]));

        return Envelope::collection($response, ResponseTimePoint::fromArray(...));
    }

    public function checks(string $id, ?CheckQuery $query = null): CursorPage
    {
        $query ??= CheckQuery::make();
        $response = $this->transport->send(Request::get(Path::join('services', $id, 'checks'), $query->toArray()));

        return Envelope::cursorPage(
            $response,
            CheckResult::fromArray(...),
            fn(string $cursor): CursorPage => $this->checks($id, $query->withCursor($cursor)),
        );
    }

    public function eachCheck(string $id, ?CheckQuery $query = null): Generator
    {
        return $this->checks($id, $query)->lazy();
    }

    public function incidents(string $id, ?ServiceIncidentQuery $query = null): Page
    {
        $query ??= ServiceIncidentQuery::make();
        $response = $this->transport->send(Request::get(Path::join('services', $id, 'incidents'), $query->toArray()));

        return Envelope::page(
            $response,
            Incident::fromArray(...),
            fn(int $page): Page => $this->incidents($id, $query->withPage($page)),
        );
    }

    public function maintenances(string $id, ?MaintenanceQuery $query = null): Page
    {
        $query ??= MaintenanceQuery::make();

        if ($query->hasServiceIds()) {
            throw new InvalidArgumentException('The service-scoped maintenance list takes no serviceIds filter; the service is the route. Use maintenances()->list() for a filter across services.');
        }

        $response = $this->transport->send(Request::get(Path::join('services', $id, 'maintenances'), $query->toArray()));

        return Envelope::page(
            $response,
            Maintenance::fromArray(...),
            fn(int $page): Page => $this->maintenances($id, $query->withPage($page)),
        );
    }
}
