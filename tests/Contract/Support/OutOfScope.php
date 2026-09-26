<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

/**
 * Operations of the API document the SDK deliberately does not implement, each with the
 * reason. Everything else in the document must be reachable through a public SDK method
 * (see CoverageContractTest): a new operation on the server fails that test until it is
 * either implemented or listed here.
 */
final class OutOfScope
{
    private const string ON_CALL = 'On-call scheduling (rotations, shifts, cover requests, absences) is operated by teams in the console; it is not part of the reseller surface this SDK covers.';

    private const string AGENTS = 'Agent enrolment, configuration and token rotation are called by the server agent with its own managed token, never with an organization token.';

    private const string AGENT_CONDITIONS = 'Metric conditions exist for agent-monitored services only, which cannot be created through the API.';

    private const string AGENT_EVENTS = 'The agent event log belongs to agent-monitored services, which cannot be created through the API.';

    private const string LAYOUT = 'The page layout (sections and their order) is edited in the console; the SDK shapes a page through its components.';

    private const string STATUSPAGE_METRICS = 'Public metrics and their series are a console feature; a typed client for them is planned for a later release.';

    private const string INCIDENT_WRITES = 'Opening, updating and resolving incidents pages the on-call team; the first SDK release reads incidents only, writes follow once that workflow is settled for resellers.';

    private const string MAINTENANCE_WRITES = 'Maintenance windows are read-only in the first SDK release; scheduling and driving them follows together with the incident writes.';

    /**
     * @return array<string, string> `METHOD /path` => reason
     */
    public static function operations(): array
    {
        return [
            'GET /oncall/absences' => self::ON_CALL,
            'POST /oncall/absences' => self::ON_CALL,
            'DELETE /oncall/absences/{absence}' => self::ON_CALL,
            'GET /oncall/on-call' => self::ON_CALL,
            'GET /oncall/schedules' => self::ON_CALL,
            'POST /oncall/schedules' => self::ON_CALL,
            'GET /oncall/schedules/{schedule}' => self::ON_CALL,
            'PUT /oncall/schedules/{schedule}' => self::ON_CALL,
            'DELETE /oncall/schedules/{schedule}' => self::ON_CALL,
            'GET /oncall/schedules/{schedule}/on-call' => self::ON_CALL,
            'GET /oncall/schedules/{schedule}/cover-requests' => self::ON_CALL,
            'POST /oncall/schedules/{schedule}/cover-requests' => self::ON_CALL,
            'POST /oncall/cover-requests/{coverRequest}/accept' => self::ON_CALL,
            'POST /oncall/cover-requests/{coverRequest}/decline' => self::ON_CALL,
            'DELETE /oncall/cover-requests/{coverRequest}' => self::ON_CALL,
            'GET /oncall/schedules/{schedule}/overrides' => self::ON_CALL,
            'POST /oncall/schedules/{schedule}/overrides' => self::ON_CALL,
            'DELETE /oncall/schedules/{schedule}/overrides/{override}' => self::ON_CALL,
            'POST /oncall/schedules/{schedule}/shifts' => self::ON_CALL,
            'POST /oncall/schedules/{schedule}/shifts/preview' => self::ON_CALL,

            'GET /agents/config' => self::AGENTS,
            'POST /agents/enroll' => self::AGENTS,
            'POST /agents/token/rotate' => self::AGENTS,

            'GET /services/{service}/conditions' => self::AGENT_CONDITIONS,
            'POST /services/{service}/conditions' => self::AGENT_CONDITIONS,
            'GET /services/{service}/conditions/{condition}' => self::AGENT_CONDITIONS,
            'PUT /services/{service}/conditions/{condition}' => self::AGENT_CONDITIONS,
            'DELETE /services/{service}/conditions/{condition}' => self::AGENT_CONDITIONS,

            'GET /services/{service}/agent-events' => self::AGENT_EVENTS,

            'GET /statuspages/{statuspage}/layout' => self::LAYOUT,
            'PUT /statuspages/{statuspage}/layout' => self::LAYOUT,

            'GET /statuspages/{statuspage}/metrics' => self::STATUSPAGE_METRICS,
            'POST /statuspages/{statuspage}/metrics' => self::STATUSPAGE_METRICS,
            'GET /statuspages/{statuspage}/metrics/{metric}' => self::STATUSPAGE_METRICS,
            'PUT /statuspages/{statuspage}/metrics/{metric}' => self::STATUSPAGE_METRICS,
            'DELETE /statuspages/{statuspage}/metrics/{metric}' => self::STATUSPAGE_METRICS,
            'POST /statuspages/{statuspage}/metrics/{metric}/series' => self::STATUSPAGE_METRICS,
            'GET /statuspages/{statuspage}/metrics/{metric}/series/{series}' => self::STATUSPAGE_METRICS,
            'PUT /statuspages/{statuspage}/metrics/{metric}/series/{series}' => self::STATUSPAGE_METRICS,
            'DELETE /statuspages/{statuspage}/metrics/{metric}/series/{series}' => self::STATUSPAGE_METRICS,

            'POST /incidents' => self::INCIDENT_WRITES,
            'PUT /incidents/{incident}' => self::INCIDENT_WRITES,
            'DELETE /incidents/{incident}' => self::INCIDENT_WRITES,
            'POST /incidents/{incident}/updates' => self::INCIDENT_WRITES,
            'POST /incidents/{incident}/resolve' => self::INCIDENT_WRITES,

            'POST /maintenances' => self::MAINTENANCE_WRITES,
            'PUT /maintenances/{maintenance}' => self::MAINTENANCE_WRITES,
            'DELETE /maintenances/{maintenance}' => self::MAINTENANCE_WRITES,
            'POST /maintenances/{maintenance}/start' => self::MAINTENANCE_WRITES,
            'POST /maintenances/{maintenance}/complete' => self::MAINTENANCE_WRITES,
            'POST /maintenances/{maintenance}/cancel' => self::MAINTENANCE_WRITES,
            'POST /maintenances/{maintenance}/extend' => self::MAINTENANCE_WRITES,
        ];
    }
}
