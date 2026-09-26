<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\Conditions\Condition;
use LIVCK\Cloud\Builders\Conditions\DnsCondition;
use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\Conditions\IcmpCondition;
use LIVCK\Cloud\Builders\Conditions\SslCondition;
use LIVCK\Cloud\Builders\Conditions\Subject;
use LIVCK\Cloud\Builders\Conditions\TcpCondition;
use LIVCK\Cloud\Builders\HttpAuth;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\ConditionSubtypeMap;
use LIVCK\Cloud\Data\IntervalBounds;
use LIVCK\Cloud\Enums\DnsRecordType;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Enums\IpVersion;
use LIVCK\Cloud\Tests\Contract\Support\OpenApiSpec;
use LIVCK\Cloud\Tests\Fixtures\CatalogFixture;
use LIVCK\Cloud\Tests\Fixtures\PayloadPath;

/**
 * The builders against the check-type catalog the server publishes (`GET /v1/meta/check-types`,
 * vendored in CatalogFixture): every creatable type has a factory, every config field a typed
 * setter, every condition field a typed subject that offers exactly the catalog's operators.
 */

/**
 * @return array<string, Closure(): ServiceBuilder> catalog key => a builder of that type
 */
function builderFactories(): array
{
    return [
        'http' => static fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://shop.example.com/health'),
        'tcp' => static fn(): ServiceBuilder => ServiceBuilder::tcp('Database', 'db.example.com', 5432),
        'dns' => static fn(): ServiceBuilder => ServiceBuilder::dns('Zone', 'example.com'),
        'icmp' => static fn(): ServiceBuilder => ServiceBuilder::icmp('Router', '203.0.113.1'),
        'ssl' => static fn(): ServiceBuilder => ServiceBuilder::ssl('Certificate', 'shop.example.com'),
        'manual' => static fn(): ServiceBuilder => ServiceBuilder::manual('Phone support'),
    ];
}

/**
 * The typed setter of every config field, as a builder that has used it.
 *
 * @return array<string, array<string, Closure(): ServiceBuilder>> catalog key => field => builder
 */
function configSetters(): array
{
    return [
        'http' => [
            'method' => static fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://shop.example.com/')->method(HttpMethod::Post),
            'headers' => static fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://shop.example.com/')->headers(['X-Api-Key' => 'key'])->header('Accept', 'text/html'),
            'auth' => static fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://shop.example.com/')->auth(HttpAuth::bearer('token')),
            'body' => static fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://shop.example.com/')->body('{}'),
            'follow_redirects' => static fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://shop.example.com/')->followRedirects(false),
            'verify_ssl' => static fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://shop.example.com/')->verifySsl(false),
            'ip_version' => static fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://shop.example.com/')->ipVersion(IpVersion::Ipv6),
            'smart_dualstack' => static fn(): ServiceBuilder => ServiceBuilder::http('Shop', 'https://shop.example.com/')->smartDualstack(),
        ],
        'tcp' => [
            'ip_version' => static fn(): ServiceBuilder => ServiceBuilder::tcp('Database', 'db.example.com', 5432)->ipVersion(IpVersion::Ipv4),
            'smart_dualstack' => static fn(): ServiceBuilder => ServiceBuilder::tcp('Database', 'db.example.com', 5432)->smartDualstack(),
        ],
        'dns' => [
            'dns_type' => static fn(): ServiceBuilder => ServiceBuilder::dns('Zone', 'example.com')->recordType(DnsRecordType::Mx),
        ],
        'icmp' => [
            'ip_version' => static fn(): ServiceBuilder => ServiceBuilder::icmp('Router', '203.0.113.1')->ipVersion(IpVersion::Ipv6),
            'smart_dualstack' => static fn(): ServiceBuilder => ServiceBuilder::icmp('Router', '203.0.113.1')->smartDualstack(false),
        ],
        'ssl' => [
            'ip_version' => static fn(): ServiceBuilder => ServiceBuilder::ssl('Certificate', 'shop.example.com')->ipVersion(IpVersion::Ipv4),
            'smart_dualstack' => static fn(): ServiceBuilder => ServiceBuilder::ssl('Certificate', 'shop.example.com')->smartDualstack(),
        ],
        'manual' => [],
    ];
}

/**
 * Config fields left to the `config()` escape hatch on purpose, each with the reason. A
 * field listed here must not have a typed setter as well.
 *
 * @return array<string, array<string, string>> catalog key => field => reason
 */
function configEscapeHatches(): array
{
    return [];
}

/**
 * The typed subject of every condition field. Parametric fields (`json`, `header`) take
 * the key that completes the stored field name.
 *
 * @return array<string, array<string, Closure(): object>> catalog key => field => a Subject
 */
function conditionSubjects(): array
{
    return [
        'http' => [
            'status_code' => HttpCondition::statusCode(...),
            'response_time_ms' => HttpCondition::responseTimeMs(...),
            'body' => HttpCondition::body(...),
            'json' => static fn(): object => HttpCondition::json('data.status'),
            'header' => static fn(): object => HttpCondition::header('content-type'),
            'metadata.redirects_followed' => HttpCondition::redirectsFollowed(...),
            'metadata.content_length' => HttpCondition::contentLength(...),
            'metadata.protocol' => HttpCondition::protocol(...),
            'metadata.final_url' => HttpCondition::finalUrl(...),
        ],
        'tcp' => [
            'response_time_ms' => TcpCondition::responseTimeMs(...),
            'metadata.resolved_ip_count' => TcpCondition::resolvedIpCount(...),
        ],
        'dns' => [
            'response_time_ms' => DnsCondition::responseTimeMs(...),
            'metadata.ip_count' => DnsCondition::ipCount(...),
            'metadata.ips' => DnsCondition::ips(...),
            'metadata.ns_count' => DnsCondition::nsCount(...),
            'metadata.ns_records' => DnsCondition::nsRecords(...),
            'metadata.mx_count' => DnsCondition::mxCount(...),
            'metadata.mx_records' => DnsCondition::mxRecords(...),
            'metadata.cname' => DnsCondition::cname(...),
            'metadata.txt_count' => DnsCondition::txtCount(...),
            'metadata.txt_records' => DnsCondition::txtRecords(...),
        ],
        'icmp' => [
            'response_time_ms' => IcmpCondition::responseTimeMs(...),
            'metadata.packet_loss_pct' => IcmpCondition::packetLossPercent(...),
            'metadata.max_rtt_ms' => IcmpCondition::maxRttMs(...),
            'metadata.packets_received' => IcmpCondition::packetsReceived(...),
        ],
        'ssl' => [
            'metadata.days_until_expiry' => SslCondition::daysUntilExpiry(...),
            'metadata.issuer' => SslCondition::issuer(...),
            'metadata.subject' => SslCondition::subject(...),
            'metadata.dns_names' => SslCondition::dnsNames(...),
            'metadata.tls_version' => SslCondition::tlsVersion(...),
            'metadata.cipher_suite' => SslCondition::cipherSuite(...),
            'metadata.chain_length' => SslCondition::chainLength(...),
        ],
        'manual' => [],
    ];
}

/**
 * @return array<string, string> operator wire value => the method a subject offers for it
 */
function operatorMethods(): array
{
    return [
        'eq' => 'eq',
        'neq' => 'neq',
        'gt' => 'gt',
        'gte' => 'gte',
        'lt' => 'lt',
        'lte' => 'lte',
        'in' => 'in',
        'not_in' => 'notIn',
        'contains' => 'contains',
        'not_contains' => 'notContains',
    ];
}

/**
 * A value the operator method accepts, read from its parameter type.
 */
function sampleArgument(ReflectionMethod $method): mixed
{
    $type = $method->getParameters()[0]->getType();
    $names = [];

    foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $member) {
        if ($member instanceof ReflectionNamedType) {
            $names[] = $member->getName();
        }
    }

    return match (true) {
        in_array('int', $names, true) => 1,
        in_array('float', $names, true) => 1.5,
        in_array('string', $names, true) => 'value',
        in_array('array', $names, true) => [1],
        default => 'value',
    };
}

describe('builders against the catalog', function (): void {
    it('has a factory for every creatable check type, and none for a type the catalog lacks', function (): void {
        $catalog = CheckTypeCatalog::fromArray(CatalogFixture::data());
        $factories = builderFactories();

        expect(array_keys($factories))->toEqualCanonicalizing($catalog->keys());

        foreach ($catalog->all() as $definition) {
            $factory = $factories[$definition->key] ?? null;

            expect($factory)->toBeInstanceOf(Closure::class, sprintf('No builder factory for %s checks.', $definition->key));

            if (! $factory instanceof Closure) {
                continue;
            }

            $builder = $factory();
            $builder->validate($catalog);

            expect($builder->checkType()->value)->toBe($definition->key)
                ->and($builder->toArray()['check_type'] ?? null)->toBe($definition->key)
                ->and($builder->target() !== null)->toBe($definition->targetRequired, sprintf('%s checks %s a target.', $definition->key, $definition->targetRequired ? 'need' : 'take no'));
        }
    });

    it('offers a typed setter for every config field, or names the field as an escape hatch', function (): void {
        $catalog = CheckTypeCatalog::fromArray(CatalogFixture::data());
        $setters = configSetters();
        $hatches = configEscapeHatches();

        foreach ($catalog->all() as $definition) {
            foreach ($definition->fields as $field) {
                $setter = $setters[$definition->key][$field->name] ?? null;
                $hatch = $hatches[$definition->key][$field->name] ?? null;

                if ($hatch !== null) {
                    expect($setter)->toBeNull(sprintf('config.%s of %s checks has a typed setter and an escape-hatch entry; keep one.', $field->name, $definition->key));

                    continue;
                }

                expect($setter)->toBeInstanceOf(Closure::class, sprintf(
                    'config.%s (%s) of %s checks has neither a typed setter nor an entry in configEscapeHatches().',
                    $field->name,
                    $field->type,
                    $definition->key,
                ));

                if (! $setter instanceof Closure) {
                    continue;
                }

                $builder = $setter();
                $builder->validate($catalog);

                expect($builder->checkType()->value)->toBe($definition->key)
                    ->and(PayloadPath::config($builder->toArray()))->toHaveKey($field->name);
            }

            foreach (array_keys($setters[$definition->key] ?? []) as $name) {
                expect($definition->hasField($name))->toBeTrue(sprintf('The setter for config.%s of %s checks has no field in the catalog.', $name, $definition->key));
            }
        }
    });

    it('offers a typed subject with exactly the catalog operators for every condition field', function (): void {
        $catalog = CheckTypeCatalog::fromArray(CatalogFixture::data());
        $subjects = conditionSubjects();

        foreach ($catalog->all() as $definition) {
            foreach ($definition->conditions->fields as $field) {
                $factory = $subjects[$definition->key][$field->field] ?? null;

                expect($factory)->toBeInstanceOf(Closure::class, sprintf('The condition field %s of %s checks has no typed subject.', $field->field, $definition->key));

                if (! $factory instanceof Closure) {
                    continue;
                }

                $subject = $factory();

                expect($subject)->toBeInstanceOf(Subject::class);

                $offered = [];

                foreach (operatorMethods() as $operator => $method) {
                    if (! method_exists($subject, $method)) {
                        continue;
                    }

                    $offered[] = $operator;
                    $reflection = new ReflectionMethod($subject, $method);
                    $condition = $reflection->invoke($subject, sampleArgument($reflection));

                    expect($condition)->toBeInstanceOf(Condition::class);

                    if ($condition instanceof Condition) {
                        expect($condition->operator)->toBe($operator)
                            ->and($field->matches($condition->field))->toBeTrue(sprintf('%s() of the subject for %s produced the field "%s".', $method, $field->field, $condition->field));
                    }
                }

                expect($offered)->toEqualCanonicalizing($field->operators, sprintf(
                    'The subject for %s of %s checks offers [%s], the catalog allows [%s].',
                    $field->field,
                    $definition->key,
                    implode(', ', $offered),
                    implode(', ', $field->operators),
                ));
            }

            foreach (array_keys($subjects[$definition->key] ?? []) as $name) {
                expect($definition->conditions->field($name))->not->toBeNull(sprintf('The subject for %s of %s checks has no condition field in the catalog.', $name, $definition->key));
            }
        }
    });

    it('covers every DNS record type and the condition fields each one produces', function (): void {
        $catalog = CheckTypeCatalog::fromArray(CatalogFixture::data());
        $map = $catalog->type('dns')->conditions->bySubtype;

        expect($map)->toBeInstanceOf(ConditionSubtypeMap::class);

        if (! $map instanceof ConditionSubtypeMap) {
            return;
        }

        $recordTypes = array_map(
            static fn(DnsRecordType $type): string => $type->value,
            array_filter(DnsRecordType::cases(), static fn(DnsRecordType $type): bool => ! $type->isUnrecognized()),
        );

        expect($map->field)->toBe('dns_type')
            ->and($map->subtypes())->toEqualCanonicalizing($recordTypes);

        foreach ($map->subtypes() as $subtype) {
            foreach ($map->fieldsFor($subtype) ?? [] as $name) {
                expect(array_key_exists($name, conditionSubjects()['dns']))->toBeTrue(sprintf('No typed subject for %s, which %s lookups produce.', $name, $subtype));
            }
        }
    });

    it('keeps every default interval inside the catalog range and above the minimum the document sets', function (): void {
        $catalog = CheckTypeCatalog::fromArray(CatalogFixture::data());
        $minimum = OpenApiSpec::load()->schemaAt('/components/schemas/StoreServiceRequest/properties/settings/properties/interval_seconds')['minimum'] ?? null;

        expect($minimum)->toBeInt();

        foreach (builderFactories() as $key => $factory) {
            $definition = $catalog->type($key);
            $interval = $factory()->intervalSeconds();

            if ($interval === null) {
                expect($definition->targetRequired)->toBeFalse(sprintf('%s checks are monitored but their builder sends no interval.', $key));

                continue;
            }

            expect($interval)->toBeGreaterThanOrEqual(is_int($minimum) ? $minimum : 0);

            if ($definition->interval instanceof IntervalBounds) {
                expect($definition->interval->allows($interval))->toBeTrue(sprintf(
                    'The default interval of %d s for %s checks lies outside the catalog range %s to %s.',
                    $interval,
                    $key,
                    $definition->interval->min ?? 'any',
                    $definition->interval->max ?? 'any',
                ));
            }
        }
    });
});
