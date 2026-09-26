<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\CheckTypeDefinition;
use LIVCK\Cloud\Data\ConditionField;
use LIVCK\Cloud\Data\ConditionRule;
use LIVCK\Cloud\Data\ConfigField;
use LIVCK\Cloud\Data\IntervalBounds;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Enums\ConditionOutcome;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Tests\Fixtures\CatalogFixture;

function catalog(): CheckTypeCatalog
{
    return CheckTypeCatalog::fromArray(CatalogFixture::data());
}

it('hydrates every type of the real catalog', function (): void {
    $catalog = catalog();

    expect($catalog->keys())->toBe(['http', 'tcp', 'dns', 'icmp', 'ssl', 'manual'])
        ->and($catalog->all())->toHaveCount(6)
        ->and($catalog->has('http'))->toBeTrue()
        ->and($catalog->has(CheckType::Ssl))->toBeTrue()
        ->and($catalog->has(CheckType::Heartbeat))->toBeFalse()
        ->and($catalog->has(CheckType::Unrecognized))->toBeFalse()
        ->and($catalog->find('agent'))->toBeNull()
        ->and($catalog->type(CheckType::Http))->toBeInstanceOf(CheckTypeDefinition::class);

    $http = $catalog->type('http');

    expect($http->key)->toBe('http')
        ->and($http->checkType())->toBe(CheckType::Http)
        ->and($http->label)->toBe('HTTP/HTTPS')
        ->and($http->description)->toBe('Monitors HTTP endpoints (websites, APIs)')
        ->and($http->targetRequired)->toBeTrue()
        ->and($http->fieldNames())->toBe(['method', 'headers', 'auth', 'body', 'follow_redirects', 'verify_ssl', 'ip_version', 'smart_dualstack'])
        ->and($http->hasField('headers'))->toBeTrue()
        ->and($http->hasField('port'))->toBeFalse()
        ->and($http->interval)->toBeNull()
        ->and($http->raw)->toBe(CatalogFixture::data()['http']);

    $manual = $catalog->type('manual');

    expect($manual->targetRequired)->toBeFalse()
        ->and($manual->fields)->toBe([])
        ->and($manual->conditions->fields)->toBe([])
        ->and($manual->conditions->defaults)->toBe([])
        ->and($manual->conditions->bySubtype)->toBeNull();
});

it('describes config fields, their options and defaults', function (): void {
    $http = catalog()->type('http');
    $method = $http->field('method');
    $headers = $http->field('headers');
    $auth = $http->field('auth');

    expect($method)->toBeInstanceOf(ConfigField::class)
        ->and($method?->type)->toBe('select')
        ->and($method?->isSelect())->toBeTrue()
        ->and($method?->label)->toBe('HTTP Method')
        ->and($method?->required)->toBeFalse()
        ->and($method?->secret)->toBeFalse()
        ->and($method?->help)->toBeNull()
        ->and($method?->options)->toBe(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'])
        ->and($method?->hasDefault)->toBeTrue()
        ->and($method?->default)->toBe('GET')
        ->and($method?->allowsOption('POST'))->toBeTrue()
        ->and($method?->allowsOption(HttpMethod::Post))->toBeTrue()
        ->and($method?->allowsOption('TRACE'))->toBeFalse()
        ->and($method?->allowsOption(['GET']))->toBeFalse()
        ->and($headers?->secret)->toBeTrue()
        ->and($headers?->isSelect())->toBeFalse()
        ->and($headers?->allowsOption('anything'))->toBeTrue()
        ->and($headers?->default)->toBe([])
        ->and($auth?->default)->toBe(['type' => 'none'])
        ->and($http->field('verify_ssl')?->isBoolean())->toBeTrue()
        ->and(catalog()->type('dns')->field('dns_type')?->required)->toBeTrue();
});

it('describes condition fields, defaults and the subtype map', function (): void {
    $http = catalog()->type('http')->conditions;
    $status = $http->field('status_code');
    $json = $http->field('json');

    expect($status)->toBeInstanceOf(ConditionField::class)
        ->and($status?->type)->toBe('number')
        ->and($status?->operators)->toBe(['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in'])
        ->and($status?->unit)->toBeNull()
        ->and($status?->parametric)->toBeFalse()
        ->and($status?->allows(ConditionOperator::In))->toBeTrue()
        ->and($status?->allows('contains'))->toBeFalse()
        ->and($status?->matches('status_code'))->toBeTrue()
        ->and($status?->matches('status_code.x'))->toBeFalse()
        ->and($http->field('response_time_ms')?->unit)->toBe('ms')
        ->and($json?->parametric)->toBeTrue()
        ->and($json?->prefix)->toBe('json.')
        ->and($json?->keyLabel)->toBe('JSON path')
        ->and($json?->keyPlaceholder)->toBe('data.status')
        ->and($json?->keyHelp)->toContain('Dot path')
        ->and($json?->matches('json.data.status'))->toBeTrue()
        ->and($json?->matches('json.'))->toBeFalse()
        ->and($json?->matches('json'))->toBeFalse()
        ->and($http->resolve('json.data.status')?->field)->toBe('json')
        ->and($http->resolve('header.x-cache')?->field)->toBe('header')
        ->and($http->resolve('status_code')?->field)->toBe('status_code')
        ->and($http->resolve('nope'))->toBeNull()
        ->and($http->bySubtype)->toBeNull()
        ->and($http->fieldsFor('anything'))->toBe($http->fields);

    $defaults = $http->defaults;

    expect($defaults)->toHaveCount(1)
        ->and($defaults[0])->toBeInstanceOf(ConditionRule::class)
        ->and($defaults[0]->field)->toBe('status_code')
        ->and($defaults[0]->operator)->toBe(ConditionOperator::Gte)
        ->and($defaults[0]->value)->toBe(400)
        ->and($defaults[0]->outcome)->toBe(ConditionOutcome::Down);

    $dns = catalog()->type('dns')->conditions;

    expect($dns->bySubtype?->field)->toBe('dns_type')
        ->and($dns->bySubtype?->subtypes())->toBe(['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME'])
        ->and($dns->bySubtype?->fieldsFor('NS'))->toBe(['response_time_ms', 'metadata.ns_count', 'metadata.ns_records'])
        ->and($dns->bySubtype?->fieldsFor('SRV'))->toBeNull()
        ->and(array_map(static fn(ConditionField $field): string => $field->field, $dns->fieldsFor('CNAME')))->toBe(['response_time_ms', 'metadata.cname'])
        ->and($dns->fieldsFor('SRV'))->toBe($dns->fields)
        ->and($dns->fieldsFor(null))->toBe($dns->fields);
});

it('describes the interval range of a type that bounds it', function (): void {
    $interval = catalog()->type('ssl')->interval;

    expect($interval)->toBeInstanceOf(IntervalBounds::class)
        ->and($interval?->default)->toBe(21600)
        ->and($interval?->min)->toBe(3600)
        ->and($interval?->max)->toBe(604800)
        ->and($interval?->allows(3600))->toBeTrue()
        ->and($interval?->allows(3599))->toBeFalse()
        ->and($interval?->allows(604801))->toBeFalse()
        ->and((new IntervalBounds(null, null, null, []))->allows(1))->toBeTrue();
});

it('keeps unknown operators and outcomes of a default readable', function (): void {
    $catalog = CheckTypeCatalog::fromArray(['x' => [
        'key' => 'x',
        'label' => 'X',
        'description' => null,
        'target_required' => true,
        'fields' => [],
        'conditions' => ['fields' => [], 'defaults' => [['field' => 'f', 'operator' => 'between', 'value' => [1, 2], 'status' => 'flapping']]],
    ]]);

    $rule = $catalog->type('x')->conditions->defaults[0];

    expect($rule->operator)->toBe(ConditionOperator::Unrecognized)
        ->and($rule->outcome)->toBe(ConditionOutcome::Unrecognized)
        ->and($rule->raw['operator'])->toBe('between');
});

it('names the offered types when asked for one it does not have', function (): void {
    expect(fn(): CheckTypeDefinition => catalog()->type('agent'))->toThrow(InvalidArgumentException::class, 'no "agent" type; it offers http, tcp, dns, icmp, ssl, manual');
});
