<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\Conditions\Condition;
use LIVCK\Cloud\Builders\Conditions\CustomCondition;
use LIVCK\Cloud\Builders\Conditions\DnsCondition;
use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\Conditions\IcmpCondition;
use LIVCK\Cloud\Builders\Conditions\SslCondition;
use LIVCK\Cloud\Builders\Conditions\TcpCondition;
use LIVCK\Cloud\Enums\ConditionOperator;
use LIVCK\Cloud\Enums\ConditionOutcome;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

it('defaults the outcome to down and switches it with degraded()', function (): void {
    expect(HttpCondition::statusCode()->gte(500)->toArray())->toBe(['field' => 'status_code', 'operator' => 'gte', 'value' => 500, 'status' => 'down'])
        ->and(HttpCondition::responseTimeMs()->gt(2000)->degraded()->toArray())->toBe(['field' => 'response_time_ms', 'operator' => 'gt', 'value' => 2000, 'status' => 'degraded'])
        ->and(HttpCondition::body()->contains('OK')->degraded()->down()->outcome)->toBe('down')
        ->and(HttpCondition::body()->contains('OK')->withOutcome('degraded')->outcome)->toBe('degraded');
});

it('produces a condition of the family that created it', function (): void {
    expect(HttpCondition::json('data.status')->eq('ok'))->toBeInstanceOf(HttpCondition::class)
        ->and(TcpCondition::resolvedIpCount()->lt(2))->toBeInstanceOf(TcpCondition::class)
        ->and(DnsCondition::cname()->eq('edge.example.net.'))->toBeInstanceOf(DnsCondition::class)
        ->and(IcmpCondition::packetsReceived()->neq(4))->toBeInstanceOf(IcmpCondition::class)
        ->and(SslCondition::issuer()->notContains('Test CA'))->toBeInstanceOf(SslCondition::class)
        ->and(HttpCondition::statusCode()->gte(500)->degraded())->toBeInstanceOf(HttpCondition::class);
});

it('writes every operator of the catalog in its wire form', function (): void {
    expect(HttpCondition::statusCode()->eq(200)->operator)->toBe('eq')
        ->and(HttpCondition::statusCode()->neq(200)->operator)->toBe('neq')
        ->and(HttpCondition::statusCode()->gt(299)->operator)->toBe('gt')
        ->and(HttpCondition::statusCode()->lt(200)->operator)->toBe('lt')
        ->and(HttpCondition::statusCode()->lte(299)->operator)->toBe('lte')
        ->and(HttpCondition::statusCode()->notIn([200, 204])->toArray()['value'])->toBe([200, 204])
        ->and(HttpCondition::statusCode()->notIn([200])->operator)->toBe('not_in')
        ->and(HttpCondition::json('items.#')->gte(1)->field)->toBe('json.items.#')
        ->and(HttpCondition::json('a')->lte(1.5)->value)->toBe(1.5)
        ->and(HttpCondition::json('a')->notContains('x')->operator)->toBe('not_contains')
        ->and(HttpCondition::json('a')->neq(false)->value)->toBeFalse()
        ->and(HttpCondition::header('X-Cache')->eq('HIT')->field)->toBe('header.X-Cache')
        ->and(HttpCondition::header('X-Cache')->notContains('MISS')->operator)->toBe('not_contains')
        ->and(HttpCondition::body()->notContains('error')->operator)->toBe('not_contains')
        ->and(HttpCondition::responseTimeMs()->gte(1)->operator)->toBe('gte')
        ->and(HttpCondition::responseTimeMs()->lt(1)->operator)->toBe('lt')
        ->and(HttpCondition::responseTimeMs()->lte(1)->operator)->toBe('lte')
        ->and(SslCondition::tlsVersion()->eq('TLS 1.3')->operator)->toBe('eq')
        ->and(SslCondition::tlsVersion()->neq('TLS 1.3')->operator)->toBe('neq')
        ->and(SslCondition::tlsVersion()->in(['TLS 1.0', 'TLS 1.1'])->operator)->toBe('in')
        ->and(SslCondition::tlsVersion()->contains('1.')->operator)->toBe('contains')
        ->and(SslCondition::tlsVersion()->notContains('1.3')->operator)->toBe('not_contains')
        ->and(SslCondition::subject()->eq('shop.example.com')->field)->toBe('metadata.subject')
        ->and(SslCondition::cipherSuite()->contains('GCM')->field)->toBe('metadata.cipher_suite')
        ->and(DnsCondition::responseTimeMs()->gt(100)->field)->toBe('response_time_ms')
        ->and(DnsCondition::ips()->contains('93.184.216.34')->field)->toBe('metadata.ips')
        ->and(DnsCondition::nsRecords()->notContains('ns9')->field)->toBe('metadata.ns_records')
        ->and(DnsCondition::mxRecords()->contains('mx1')->field)->toBe('metadata.mx_records')
        ->and(DnsCondition::txtCount()->gte(1)->field)->toBe('metadata.txt_count')
        ->and(DnsCondition::txtRecords()->contains('v=spf1')->field)->toBe('metadata.txt_records')
        ->and(IcmpCondition::responseTimeMs()->gt(50)->field)->toBe('response_time_ms')
        ->and(IcmpCondition::maxRttMs()->lte(250.5)->value)->toBe(250.5)
        ->and(TcpCondition::responseTimeMs()->gte(10)->field)->toBe('response_time_ms');
});

it('builds a custom condition from enums or plain strings', function (): void {
    $typed = Condition::custom('metadata.future', ConditionOperator::Gte, 1, ConditionOutcome::Degraded);
    $plain = Condition::custom('metadata.future', 'between', [1, 2], 'degraded');

    expect($typed)->toBeInstanceOf(CustomCondition::class)
        ->and($typed->toArray())->toBe(['field' => 'metadata.future', 'operator' => 'gte', 'value' => 1, 'status' => 'degraded'])
        ->and($plain->toArray())->toBe(['field' => 'metadata.future', 'operator' => 'between', 'value' => [1, 2], 'status' => 'degraded'])
        ->and(Condition::custom('x', 'eq', true)->outcome)->toBe('down');
});

it('is immutable', function (): void {
    $down = HttpCondition::statusCode()->gte(500);
    $degraded = $down->degraded();

    expect($down->outcome)->toBe('down')
        ->and($degraded->outcome)->toBe('degraded');
});

it('refuses what can never be sent', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank field' => [fn(): Condition => Condition::custom(' ', 'eq', 1), 'field must not be blank'],
    'blank operator' => [fn(): Condition => Condition::custom('x', '', 1), 'operator must not be blank'],
    'blank outcome' => [fn(): Condition => Condition::custom('x', 'eq', 1, ' '), 'outcome must not be blank'],
    'unrecognized operator' => [fn(): Condition => Condition::custom('x', ConditionOperator::Unrecognized, 1), 'Unrecognized'],
    'unrecognized outcome' => [fn(): Condition => Condition::custom('x', 'eq', 1, ConditionOutcome::Unrecognized), 'Unrecognized'],
    'object value' => [fn(): Condition => Condition::custom('x', 'eq', new stdClass()), 'scalar'],
    'nested list value' => [fn(): Condition => Condition::custom('x', 'in', [[1]]), 'scalar'],
    'map value' => [fn(): Condition => Condition::custom('x', 'in', ['a' => 1]), 'scalar'],
    'blank json path' => [fn(): mixed => HttpCondition::json(''), 'path'],
    'blank header name' => [fn(): mixed => HttpCondition::header(' '), 'header name'],
]);
