<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\HeartbeatBuilder;
use LIVCK\Cloud\Builders\HeartbeatDeclaration;
use LIVCK\Cloud\Builders\HeartbeatSchedule;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

it('writes application, environment and every heartbeat in order', function (): void {
    $declaration = HeartbeatDeclaration::of(
        'shop',
        'production',
        HeartbeatBuilder::schedule('export:nightly', HeartbeatSchedule::cron('0 3 * * *', 'Europe/Berlin')),
        HeartbeatBuilder::queue('queue:default', HeartbeatSchedule::every(60)),
    );

    expect($declaration->app())->toBe('shop')
        ->and($declaration->environment())->toBe('production')
        ->and($declaration->keys())->toBe(['export:nightly', 'queue:default'])
        ->and($declaration->toArray())->toBe([
            'app' => 'shop',
            'environment' => 'production',
            'heartbeats' => [
                ['key' => 'export:nightly', 'kind' => 'schedule', 'schedule' => ['type' => 'cron', 'expression' => '0 3 * * *', 'timezone' => 'Europe/Berlin']],
                ['key' => 'queue:default', 'kind' => 'queue', 'schedule' => ['type' => 'simple', 'period_seconds' => 60]],
            ],
        ]);
});

it('sends an empty list as an empty list', function (): void {
    expect(HeartbeatDeclaration::of('shop', 'staging')->toArray())->toBe(['app' => 'shop', 'environment' => 'staging', 'heartbeats' => []]);
});

it('adds heartbeats without changing the original', function (): void {
    $base = HeartbeatDeclaration::of('shop', 'production', HeartbeatBuilder::job('import', HeartbeatSchedule::every(3600)));
    $more = $base->add(HeartbeatBuilder::job('backup', HeartbeatSchedule::every(86400)), HeartbeatBuilder::job('123', HeartbeatSchedule::every(60)));

    expect($base->keys())->toBe(['import'])
        ->and($more->keys())->toBe(['import', 'backup', '123'])
        ->and(array_column($more->toArray()['heartbeats'], 'key'))->toBe(['import', 'backup', '123']);
});

it('refuses a key declared twice, case-sensitively like the server', function (): void {
    $declaration = HeartbeatDeclaration::of('shop', 'production', HeartbeatBuilder::job('import', HeartbeatSchedule::every(3600)));

    expect(fn(): HeartbeatDeclaration => $declaration->add(HeartbeatBuilder::queue('import', HeartbeatSchedule::every(60))))
        ->toThrow(InvalidArgumentException::class, '"import" is declared twice')
        ->and($declaration->add(HeartbeatBuilder::job('Import', HeartbeatSchedule::every(60)))->keys())->toBe(['import', 'Import']);
});

it('refuses a blank application or environment', function (string $app, string $environment, string $message): void {
    expect(fn(): HeartbeatDeclaration => HeartbeatDeclaration::of($app, $environment))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'application' => [' ', 'production', 'application'],
    'environment' => ['shop', '', 'environment'],
]);

it('leaves the format of the names to the server', function (): void {
    expect(HeartbeatDeclaration::of('My Shop', 'Prod')->toArray())->toMatchArray(['app' => 'My Shop', 'environment' => 'Prod']);
});
