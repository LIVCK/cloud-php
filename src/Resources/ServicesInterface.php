<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\AgentMetrics;
use LIVCK\Cloud\Data\AgentMetricsHistory;
use LIVCK\Cloud\Data\CheckResult;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\Incident;
use LIVCK\Cloud\Data\Maintenance;
use LIVCK\Cloud\Data\ResponseTimePoint;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\ServiceMetrics;
use LIVCK\Cloud\Data\UptimeDay;
use LIVCK\Cloud\Enums\AgentMetricsRange;
use LIVCK\Cloud\Enums\MetricsRange;
use LIVCK\Cloud\Enums\StatusOverride;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\CatalogValidationException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\PlanLimitException;
use LIVCK\Cloud\Exceptions\ServiceUnavailableException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\CursorPage;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Query\CheckQuery;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Query\ServiceIncidentQuery;
use LIVCK\Cloud\Query\ServiceQuery;

/**
 * Services: what is monitored, how, and what came of it.
 *
 * Abilities: reads need `services.view`, creation `services.create`, changes (update,
 * pause, resume, status override) `services.edit`, deletion `services.delete`. The
 * service-scoped incident and maintenance lists additionally need `incidents.view` /
 * `maintenances.view`. The number of services is a plan limit ({@see PlanLimitException}
 * with key `services`), as are the check interval, the number of locations and of
 * conditions per service ({@see ValidationException} naming the field).
 *
 * Every write except deletion is refused with a {@see ServiceUnavailableException} while
 * monitoring is frozen platform-wide; reads keep working. A service mirrored from a
 * third-party status page (`statuspage` type) cannot be updated through the API (422).
 */
interface ServicesInterface
{
    /**
     * One page of services, newest first, optionally those carrying one tag.
     *
     * @return Page<Service>
     *
     * @throws ApiException
     */
    public function list(?ServiceQuery $query = null): Page;

    /**
     * Every matching service across all pages, one request per page as the iteration advances.
     *
     * @return Generator<int, Service>
     *
     * @throws ApiException
     */
    public function each(?ServiceQuery $query = null): Generator;

    /**
     * @throws NotFoundException
     * @throws ApiException
     */
    public function get(string $id): Service;

    /**
     * Create a service, configured and monitored from the first check. With a catalog
     * (`$client->checkTypes()`) the payload is checked against it before anything is sent.
     *
     * `$idempotencyKey` replaces the generated `Idempotency-Key` for this call: a stable
     * key (e.g. an order number) makes the create safe to repeat after a crash, for 24
     * hours, per token.
     *
     * @throws CatalogValidationException when the payload does not match the given catalog
     * @throws InvalidArgumentException for a malformed idempotency key (nothing is sent)
     * @throws ValidationException for a malformed target, an unknown config key or condition, an interval below the plan's minimum, too many locations or conditions
     * @throws PlanLimitException when the plan's service limit is reached
     * @throws ApiException
     */
    public function create(ServiceBuilder $builder, ?CheckTypeCatalog $validateAgainst = null, ?string $idempotencyKey = null): Service;

    /**
     * Change name, target, settings or tags; see {@see UpdateService} for what each part
     * replaces and what it keeps.
     *
     * @throws InvalidArgumentException when the update carries no change
     * @throws NotFoundException
     * @throws ValidationException
     * @throws ApiException
     */
    public function update(string $id, UpdateService $changes): Service;

    /**
     * Delete a service. Incidents and maintenance windows that also carry other services
     * are only detached. Those the service carried ALONE are, by default, closed rather
     * than erased: open incidents are resolved, active windows cancelled. The two flags
     * erase them instead; each needs the token ability of that resource
     * (`incidents.delete`, `maintenances.delete`), else the whole call is a
     * {@see PermissionDeniedException} and nothing is touched.
     *
     * @throws NotFoundException
     * @throws PermissionDeniedException
     * @throws ApiException
     */
    public function delete(string $id, bool $deleteOrphanedIncidents = false, bool $deleteOrphanedMaintenances = false): void;

    /**
     * Stop checking; the service reads `paused`. Pausing a paused service is a no-op.
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function pause(string $id): Service;

    /**
     * Resume checking. A resume re-admits the service against the plan's service quota.
     *
     * @throws NotFoundException
     * @throws PlanLimitException when the quota has no room for it
     * @throws ApiException
     */
    public function resume(string $id): Service;

    /**
     * Pin the service (and every statuspage component in front of it) to a status until
     * the override is removed, whatever the checks measure. This is how a `manual` service
     * is switched at all; on a measured service it masks the live result. The reason is
     * mandatory (at most 500 characters), shown to the team and never published.
     * Re-applying overwrites the current override.
     *
     * @throws InvalidArgumentException for a blank reason
     * @throws NotFoundException
     * @throws ValidationException
     * @throws ApiException
     */
    public function applyStatusOverride(string $id, StatusOverride $status, string $reason): Service;

    /**
     * Back to the measured status. Removing an override that is not set is a no-op.
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function removeStatusOverride(string $id): Service;

    /**
     * Availability, latency percentiles and check counts over a range.
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function metrics(string $id, MetricsRange $range = MetricsRange::TwentyFourHours): ServiceMetrics;

    /**
     * Availability per calendar day for the last `$days` days (1 to 90), oldest first. A
     * day without measurements is `no_data`; read {@see UptimeDay::uptime()}.
     *
     * @return list<UptimeDay>
     *
     * @throws InvalidArgumentException for days outside 1..90
     * @throws NotFoundException
     * @throws ApiException
     */
    public function uptime(string $id, int $days = 30): array;

    /**
     * The response-time trend over a range, one point per hour (or per five minutes while
     * the history is short), oldest first. Empty without measurements.
     *
     * @return list<ResponseTimePoint>
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function responseTimes(string $id, MetricsRange $range = MetricsRange::TwentyFourHours): array;

    /**
     * The current figures of the server behind an `agent` service: the latest value of every
     * host key of the metric catalog (`sys.cpu.total_pct`, …), its disks, graphics cards and
     * drives, and the checks the server runs itself. A server that has not reported yet answers
     * with nulls and empty lists, and so do figures that cannot be read right now.
     *
     * @throws NotFoundException for a service that is no `agent` service, as for an unknown one
     * @throws ApiException
     */
    public function agentMetrics(string $id): AgentMetrics;

    /**
     * The course of the same figures over a window, as the console charts it: a few hundred
     * buckets with the average and peak per key, plus each key's figures over the window. A
     * window longer than the plan keeps data for is shortened to the retention
     * (`windowSeconds` tells). `$keys` picks keys of the metric catalog (`sys.cpu.total_pct`,
     * `sys.disk._root.used_pct`), at most 100; without them the first 100 keys the server reported
     * in the window come back, the host's own figures first, and `availableKeys` names them all.
     * Figures that cannot be read right now come back empty.
     *
     * @throws InvalidArgumentException for a blank key, more than 100 keys or an unrecognized range (nothing is sent)
     * @throws ValidationException for a key that is no server metric of the catalog, on its entry (`keys.0`)
     * @throws NotFoundException for a service that is no `agent` service, as for an unknown one
     * @throws ApiException
     */
    public function agentMetricsHistory(string $id, AgentMetricsRange $range = AgentMetricsRange::TwentyFourHours, string ...$keys): AgentMetricsHistory;

    /**
     * The raw check history, newest first, keyset-paginated: one row per check a probe ran.
     * At most the last 90 days (less on a smaller plan). Services no probe checks answer
     * with an empty page, and so does a history that cannot be read right now.
     *
     * @return CursorPage<CheckResult>
     *
     * @throws NotFoundException
     * @throws ValidationException for a malformed cursor or window
     * @throws ApiException
     */
    public function checks(string $id, ?CheckQuery $query = null): CursorPage;

    /**
     * Every matching check across all pages, one request per page as the iteration advances.
     *
     * @return Generator<int, CheckResult>
     *
     * @throws ApiException
     */
    public function eachCheck(string $id, ?CheckQuery $query = null): Generator;

    /**
     * The incidents that touched this service, newest first, each with what the incident
     * meant for it ({@see Incident::$serviceImpact}). Needs `services.view` and
     * `incidents.view`.
     *
     * @return Page<Incident>
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function incidents(string $id, ?ServiceIncidentQuery $query = null): Page;

    /**
     * The maintenance windows covering this service, newest scheduled start first. Needs
     * `services.view` and `maintenances.view`. The query's `serviceIds` has no meaning
     * here and is refused.
     *
     * @return Page<Maintenance>
     *
     * @throws InvalidArgumentException when the query carries service ids
     * @throws NotFoundException
     * @throws ApiException
     */
    public function maintenances(string $id, ?MaintenanceQuery $query = null): Page;
}
