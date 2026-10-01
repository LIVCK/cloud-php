<?php

declare(strict_types=1);

use LIVCK\Cloud\Enums\AgentMetricsRange;
use LIVCK\Cloud\Enums\AgentState;
use LIVCK\Cloud\Enums\ApiEnum;
use LIVCK\Cloud\Enums\CheckResultStatus;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Enums\ConditionOutcome;
use LIVCK\Cloud\Enums\DnsRecordType;
use LIVCK\Cloud\Enums\EnrollmentKeyStatus;
use LIVCK\Cloud\Enums\EnrollmentKeyType;
use LIVCK\Cloud\Enums\HttpAuthType;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\IncidentKind;
use LIVCK\Cloud\Enums\IncidentSeverity;
use LIVCK\Cloud\Enums\IncidentStatus;
use LIVCK\Cloud\Enums\IpFamily;
use LIVCK\Cloud\Enums\IpVersion;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Enums\MaintenanceType;
use LIVCK\Cloud\Enums\MetricsRange;
use LIVCK\Cloud\Enums\PausedReason;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Enums\ServiceImpact;
use LIVCK\Cloud\Enums\ServiceStatus;
use LIVCK\Cloud\Enums\StatusOverride;
use LIVCK\Cloud\Enums\TokenType;
use LIVCK\Cloud\Enums\UptimeDayStatus;

// Every value the server knows, copied from its enums and constants, plus the fallback.
it('carries exactly the server\'s values and survives an unknown one', function (string $enum, array $values): void {
    $first = $values[0] ?? null;

    if (! is_a($enum, ApiEnum::class, true) || ! is_a($enum, BackedEnum::class, true) || ! is_string($first)) {
        throw new RuntimeException($enum . ' is not an API enum with values.');
    }

    $backing = array_map(static fn(BackedEnum $case): string => (string) $case->value, $enum::cases());

    expect($backing)->toBe([...$values, '__unrecognized__'])
        ->and($enum::fromApi('__nope__')->isUnrecognized())->toBeTrue()
        ->and($enum::fromApi($first)->isUnrecognized())->toBeFalse();
})->with([
    'CheckType' => [CheckType::class, ['http', 'tcp', 'dns', 'icmp', 'ssl', 'manual', 'heartbeat', 'statuspage', 'agent', 'push']],
    'ServiceStatus' => [ServiceStatus::class, ['unknown', 'up', 'down', 'degraded', 'paused', 'maintenance', 'operational']],
    'StatusOverride' => [StatusOverride::class, ['up', 'down', 'degraded', 'maintenance']],
    'PausedReason' => [PausedReason::class, ['manual', 'plan_limit', 'org_deleted', 'agent_limit', 'agent_uninstalled']],
    'ProbeRole' => [ProbeRole::class, ['full', 'reachability']],
    'IncidentKind' => [IncidentKind::class, ['standard', 'agent_liveness', 'notice']],
    'IncidentStatus' => [IncidentStatus::class, ['investigating', 'identified', 'monitoring', 'resolved']],
    'IncidentSeverity' => [IncidentSeverity::class, ['minor', 'major', 'critical']],
    'ServiceImpact' => [ServiceImpact::class, ['degraded', 'partial_outage', 'major_outage']],
    'MaintenanceStatus' => [MaintenanceStatus::class, ['scheduled', 'in_progress', 'completed', 'cancelled']],
    'MaintenanceType' => [MaintenanceType::class, ['planned', 'emergency']],
    'CheckResultStatus' => [CheckResultStatus::class, ['up', 'down', 'degraded']],
    'HttpMethod' => [HttpMethod::class, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS']],
    'HttpAuthType' => [HttpAuthType::class, ['none', 'bearer', 'basic', 'api_key']],
    'IpVersion' => [IpVersion::class, ['auto', 'ipv4', 'ipv6']],
    'DnsRecordType' => [DnsRecordType::class, ['A', 'AAAA', 'MX', 'CNAME', 'TXT', 'NS']],
    'ConditionOperator' => [ConditionOperator::class, ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'not_contains']],
    'ConditionOutcome' => [ConditionOutcome::class, ['down', 'degraded']],
    'MetricsRange' => [MetricsRange::class, ['1h', '6h', '24h', '7d', '30d']],
    'UptimeDayStatus' => [UptimeDayStatus::class, ['up', 'degraded', 'down', 'no_data']],
    'TokenType' => [TokenType::class, ['user', 'managed']],
    'EnrollmentKeyType' => [EnrollmentKeyType::class, ['single', 'fleet']],
    'EnrollmentKeyStatus' => [EnrollmentKeyStatus::class, ['active', 'exhausted', 'expired', 'revoked']],
    'AgentState' => [AgentState::class, ['waiting', 'online', 'degraded', 'rebooting', 'updating', 'offline', 'stopped', 'archived', 'uninstalled']],
    'IpFamily' => [IpFamily::class, ['v4', 'v6']],
    'AgentMetricsRange' => [AgentMetricsRange::class, ['1h', '6h', '24h', '7d', '30d', '90d', '365d']],
]);

it('answers the small questions the DTOs ask', function (): void {
    expect(CheckType::Http->isMonitoredByProbes())->toBeTrue()
        ->and(CheckType::Statuspage->isMonitoredByProbes())->toBeTrue()
        ->and(CheckType::Manual->isMonitoredByProbes())->toBeFalse()
        ->and(CheckType::Unrecognized->isMonitoredByProbes())->toBeFalse()
        ->and(ServiceStatus::Up->isHealthy())->toBeTrue()
        ->and(ServiceStatus::Operational->isHealthy())->toBeTrue()
        ->and(ServiceStatus::Degraded->isHealthy())->toBeFalse()
        ->and(IncidentStatus::Monitoring->isOpen())->toBeTrue()
        ->and(IncidentStatus::Resolved->isOpen())->toBeFalse()
        ->and(ServiceImpact::Degraded->countsAsDowntime())->toBeFalse()
        ->and(ServiceImpact::MajorOutage->countsAsDowntime())->toBeTrue()
        ->and(MaintenanceStatus::InProgress->isActive())->toBeTrue()
        ->and(MaintenanceStatus::Cancelled->isActive())->toBeFalse()
        ->and(AgentState::Offline->isOutage())->toBeTrue()
        ->and(AgentState::Stopped->isOutage())->toBeFalse()
        ->and(AgentState::Rebooting->isOutage())->toBeFalse()
        ->and(AgentState::Unrecognized->isOutage())->toBeFalse();
});
