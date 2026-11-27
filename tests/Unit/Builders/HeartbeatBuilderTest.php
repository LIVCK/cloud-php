<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\HeartbeatBuilder;
use LIVCK\Cloud\Builders\HeartbeatSchedule;
use LIVCK\Cloud\Enums\HeartbeatKind;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

it('names the kind by its factory', function (Closure $factory, string $kind): void {
    $heartbeat = $factory('export:nightly', HeartbeatSchedule::every(60));

    assert($heartbeat instanceof HeartbeatBuilder);

    expect($heartbeat->key())->toBe('export:nightly')
        ->and($heartbeat->toArray())->toBe([
            'key' => 'export:nightly',
            'kind' => $kind,
            'schedule' => ['type' => 'simple', 'period_seconds' => 60],
        ]);
})->with([
    'schedule' => [HeartbeatBuilder::schedule(...), 'schedule'],
    'job' => [HeartbeatBuilder::job(...), 'job'],
    'queue' => [HeartbeatBuilder::queue(...), 'queue'],
    'custom' => [HeartbeatBuilder::custom(...), 'custom'],
]);

it('takes any kind through of()', function (): void {
    expect(HeartbeatBuilder::of(HeartbeatKind::Job, 'backup', HeartbeatSchedule::cron('0 2 * * *'))->toArray())->toBe([
        'key' => 'backup',
        'kind' => 'job',
        'schedule' => ['type' => 'cron', 'expression' => '0 2 * * *'],
    ]);
});

it('sends every option that was set, and nothing else', function (): void {
    $heartbeat = HeartbeatBuilder::schedule('export:nightly', HeartbeatSchedule::cron('0 3 * * *', 'Europe/Berlin'))
        ->name('Nightly export')
        ->grace(300)
        ->tolerance(2)
        ->maxDuration(3600);

    expect($heartbeat->toArray())->toBe([
        'key' => 'export:nightly',
        'kind' => 'schedule',
        'schedule' => ['type' => 'cron', 'expression' => '0 3 * * *', 'timezone' => 'Europe/Berlin'],
        'name' => 'Nightly export',
        'grace_seconds' => 300,
        'schedule_tolerance' => 2,
        'max_duration_seconds' => 3600,
    ]);
});

it('tells leaving the run time limit alone from removing it', function (): void {
    $base = HeartbeatBuilder::queue('queue:default', HeartbeatSchedule::every(60));

    expect($base->toArray())->not->toHaveKey('max_duration_seconds')
        ->and($base->noMaxDuration()->toArray())->toHaveKey('max_duration_seconds')
        ->and($base->noMaxDuration()->toArray()['max_duration_seconds'])->toBeNull()
        ->and($base->maxDuration(600)->noMaxDuration()->toArray()['max_duration_seconds'])->toBeNull()
        ->and($base->noMaxDuration()->maxDuration(600)->toArray()['max_duration_seconds'])->toBe(600);
});

it('is immutable', function (): void {
    $base = HeartbeatBuilder::job('import', HeartbeatSchedule::every(3600));
    $graced = $base->grace(120);

    expect($base->toArray())->not->toHaveKey('grace_seconds')
        ->and($graced->toArray()['grace_seconds'])->toBe(120);
});

it('writes any other key through attribute(), overriding a typed value', function (): void {
    $heartbeat = HeartbeatBuilder::job('import', HeartbeatSchedule::every(3600))
        ->grace(120)
        ->attribute('grace_seconds', 90)
        ->attribute('tags', ['team:data']);

    expect($heartbeat->toArray())->toMatchArray(['grace_seconds' => 90, 'tags' => ['team:data']]);
});

it('refuses what is always a mistake in the calling code', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank key' => [fn(): HeartbeatBuilder => HeartbeatBuilder::schedule('  ', HeartbeatSchedule::every(60)), 'key must not be blank'],
    'unrecognized kind' => [fn(): HeartbeatBuilder => HeartbeatBuilder::of(HeartbeatKind::Unrecognized, 'x', HeartbeatSchedule::every(60)), 'cannot be sent'],
    'blank name' => [fn(): HeartbeatBuilder => HeartbeatBuilder::job('x', HeartbeatSchedule::every(60))->name(' '), 'name must not be blank'],
    'blank attribute' => [fn(): HeartbeatBuilder => HeartbeatBuilder::job('x', HeartbeatSchedule::every(60))->attribute('', 1), 'key must not be blank'],
    'key through attribute' => [fn(): HeartbeatBuilder => HeartbeatBuilder::job('x', HeartbeatSchedule::every(60))->attribute('key', 'y'), 'set by the factory'],
]);

it('leaves the format of a key and the ranges of the options to the server', function (): void {
    $heartbeat = HeartbeatBuilder::custom('not a valid key!', HeartbeatSchedule::every(60))->grace(1)->tolerance(99);

    expect($heartbeat->toArray())->toMatchArray(['key' => 'not a valid key!', 'grace_seconds' => 1, 'schedule_tolerance' => 99]);
});
