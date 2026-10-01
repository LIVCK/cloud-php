<?php

declare(strict_types=1);

use LIVCK\Cloud\Enums\AccessType;
use LIVCK\Cloud\Enums\ApiEnum;
use LIVCK\Cloud\Enums\CheckResultStatus;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\ComponentStatus;
use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Enums\ConditionOutcome;
use LIVCK\Cloud\Enums\DnsRecordType;
use LIVCK\Cloud\Enums\EnrollmentKeyStatus;
use LIVCK\Cloud\Enums\EnrollmentKeyType;
use LIVCK\Cloud\Enums\HttpAuthType;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\IncidentKind;
use LIVCK\Cloud\Enums\IpVersion;
use LIVCK\Cloud\Enums\LogoSize;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Enums\ProbeRole;
use LIVCK\Cloud\Enums\StatusOverride;
use LIVCK\Cloud\Enums\SubscriberChannel;
use LIVCK\Cloud\Tests\Contract\Support\OpenApiSpec;
use LIVCK\Cloud\Tests\Contract\Support\Parameter;

/**
 * The wire values of an SDK enum, the `Unrecognized` placeholder left out.
 *
 * @return list<string>
 */
function enumWireValues(string $enum): array
{
    if (! is_subclass_of($enum, BackedEnum::class) || ! is_subclass_of($enum, ApiEnum::class)) {
        throw new InvalidArgumentException(sprintf('%s is not a backed API enum.', $enum));
    }

    $values = [];

    foreach ($enum::cases() as $case) {
        if (! $case->isUnrecognized()) {
            $values[] = (string) $case->value;
        }
    }

    sort($values);

    return $values;
}

/**
 * The `enum` of the schema at a pointer of the document.
 *
 * @return list<string>
 */
function schemaEnum(string $pointer): array
{
    $values = OpenApiSpec::load()->schemaAt($pointer)['enum'] ?? null;

    expect($values)->toBeArray(sprintf('The schema at %s declares no enum.', $pointer));

    $values = is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    sort($values);

    return $values;
}

/**
 * The `enum` of a query parameter (its items, for a repeatable one).
 *
 * @return list<string>
 */
function parameterEnum(string $operation, string $name): array
{
    $parameter = OpenApiSpec::load()->operation($operation)->queryParameter($name);

    expect($parameter)->toBeInstanceOf(Parameter::class, sprintf('%s declares no query parameter "%s".', $operation, $name));

    $schema = $parameter instanceof Parameter ? ($parameter->isList() ? $parameter->itemSchema() : $parameter->schema) : [];
    $values = is_array($schema['enum'] ?? null) ? array_values(array_filter($schema['enum'], is_string(...))) : [];
    sort($values);

    return $values;
}

describe('enum values', function (): void {
    it('match the schema enums of the document', function (string $enum, string $pointer): void {
        expect(enumWireValues($enum))->toBe(schemaEnum($pointer));
    })->with([
        'LogoSize' => [LogoSize::class, '/components/schemas/LogoSize'],
        'AccessType' => [AccessType::class, '/components/schemas/AccessType'],
        'SubscriberChannel' => [SubscriberChannel::class, '/components/schemas/SubscriberChannelType'],
        'ComponentStatus' => [ComponentStatus::class, '/components/schemas/ComponentStatus'],
        'HttpMethod' => [HttpMethod::class, '/components/schemas/HttpConfig/properties/method'],
        'IpVersion' => [IpVersion::class, '/components/schemas/HttpConfig/properties/ip_version'],
        'DnsRecordType' => [DnsRecordType::class, '/components/schemas/DnsConfig/properties/dns_type'],
        'HttpAuthType' => [HttpAuthType::class, '/components/schemas/HttpAuth/properties/type'],
        'ConditionOperator' => [ConditionOperator::class, '/components/schemas/ServiceCondition/properties/operator'],
        'ConditionOutcome' => [ConditionOutcome::class, '/components/schemas/ServiceCondition/properties/status'],
        'StatusOverride' => [StatusOverride::class, '/components/schemas/ApplyStatusOverrideRequest/properties/status'],
        'ProbeRole' => [ProbeRole::class, '/components/schemas/StoreServiceRequest/properties/settings/properties/probe_roles/additionalProperties'],
        'EnrollmentKeyType' => [EnrollmentKeyType::class, '/components/schemas/EnrollmentTokenType'],
        'EnrollmentKeyType (as a key reports it)' => [EnrollmentKeyType::class, '/components/schemas/EnrollmentKeyResource/properties/type'],
        'EnrollmentKeyStatus' => [EnrollmentKeyStatus::class, '/components/schemas/EnrollmentTokenStatus'],
    ]);

    it('match the query parameter enums of the document', function (string $enum, string $operation, string $parameter): void {
        expect(enumWireValues($enum))->toBe(parameterEnum($operation, $parameter));
    })->with([
        'IncidentKind' => [IncidentKind::class, 'GET /incidents', 'kind'],
        'MaintenanceStatus' => [MaintenanceStatus::class, 'GET /maintenances', 'status'],
        'CheckResultStatus' => [CheckResultStatus::class, 'GET /services/{service}/checks', 'status'],
    ]);

    it('know every check type a service can be created with', function (): void {
        $creatable = schemaEnum('/components/schemas/StoreServiceRequest/properties/check_type');

        expect(array_values(array_diff($creatable, enumWireValues(CheckType::class))))->toBe([]);
    });
});
