<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\EnrollmentKeyBuilder;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Tests\Fixtures\ServiceFixtures;

it('builds both kinds of key', function (EnrollmentKeyBuilder $builder, array $expected): void {
    expect($builder->toArray())->toBe($expected);
})->with([
    'single, server defaults' => [fn(): EnrollmentKeyBuilder => EnrollmentKeyBuilder::single(), ['type' => 'single']],
    'single, named' => [fn(): EnrollmentKeyBuilder => EnrollmentKeyBuilder::single('Customer 4711'), ['type' => 'single', 'name' => 'Customer 4711']],
    'fleet, server defaults' => [fn(): EnrollmentKeyBuilder => EnrollmentKeyBuilder::fleet(), ['type' => 'fleet']],
    'fleet with a use cap' => [fn(): EnrollmentKeyBuilder => EnrollmentKeyBuilder::fleet('Web servers', 200), ['type' => 'fleet', 'name' => 'Web servers', 'max_uses' => 200]],
    'fleet, cap only' => [fn(): EnrollmentKeyBuilder => EnrollmentKeyBuilder::fleet(maxUses: 20), ['type' => 'fleet', 'max_uses' => 20]],
]);

it('adds every option in the order it was set', function (): void {
    $expiresAt = new DateTimeImmutable('2026-10-03T12:00:00Z');

    $builder = EnrollmentKeyBuilder::single('Customer 4711')
        ->tags(ServiceFixtures::TAG_ID, 'customer:4711')
        ->expiresAt($expiresAt)
        ->allowAgentTags(false);

    expect($builder->toArray())->toBe([
        'type' => 'single',
        'name' => 'Customer 4711',
        'tags' => [ServiceFixtures::TAG_ID, 'customer:4711'],
        'expires_at' => $expiresAt,
        'agent_tags' => false,
    ])->and(EnrollmentKeyBuilder::single()->allowAgentTags()->toArray())->toBe(['type' => 'single', 'agent_tags' => true]);
});

it('takes tags by Tag, id and name, each once and trimmed, like a service', function (): void {
    $builder = EnrollmentKeyBuilder::single()->tags(
        Tag::fromArray(tagPayload(['id' => 'W2TuHYS9a6keIj7CnzU12'])),
        ServiceFixtures::TAG_ID,
        '  env=prod ',
        'env=prod',
        'W2TuHYS9a6keIj7CnzU12',
    );

    expect($builder->toArray()['tags'] ?? null)->toBe(['W2TuHYS9a6keIj7CnzU12', ServiceFixtures::TAG_ID, 'env=prod'])
        ->and(EnrollmentKeyBuilder::single()->tags()->toArray())->toBe(['type' => 'single', 'tags' => []]);
});

it('is immutable', function (): void {
    $base = EnrollmentKeyBuilder::single('Customer 4711');
    $tagged = $base->tags('customer:4711');
    $retagged = $tagged->tags('customer:4712');

    expect($base->toArray())->toBe(['type' => 'single', 'name' => 'Customer 4711'])
        ->and($tagged->toArray()['tags'] ?? null)->toBe(['customer:4711'])
        ->and($retagged->toArray()['tags'] ?? null)->toBe(['customer:4712']);
});

it('writes any key through attribute(), over a typed value', function (): void {
    $builder = EnrollmentKeyBuilder::fleet('Web servers', 20)
        ->attribute('max_uses', 40)
        ->attribute('new_option', ['a' => 1]);

    expect($builder->toArray())->toBe(['type' => 'fleet', 'name' => 'Web servers', 'max_uses' => 40, 'new_option' => ['a' => 1]]);
});

it('refuses blank names, tags and keys', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank single name' => [fn(): EnrollmentKeyBuilder => EnrollmentKeyBuilder::single('  '), 'pass null'],
    'blank fleet name' => [fn(): EnrollmentKeyBuilder => EnrollmentKeyBuilder::fleet(''), 'pass null'],
    'blank tag' => [fn(): EnrollmentKeyBuilder => EnrollmentKeyBuilder::single()->tags('customer:4711', ''), 'must not be blank'],
    'blank attribute key' => [fn(): EnrollmentKeyBuilder => EnrollmentKeyBuilder::single()->attribute(' ', 1), 'must not be blank'],
]);

it('keeps the expiry it was given, even when the caller changes that DateTime later', function (): void {
    $expiry = new DateTime('2026-10-03T12:00:00+00:00');
    $builder = EnrollmentKeyBuilder::single()->expiresAt($expiry);

    $expiry->modify('+30 days');

    expect($builder->toArray()['expires_at'] ?? null)->toEqual(new DateTimeImmutable('2026-10-03T12:00:00+00:00'));
});
