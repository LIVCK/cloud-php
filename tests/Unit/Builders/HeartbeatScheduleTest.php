<?php

declare(strict_types=1);

use LIVCK\Cloud\Builders\HeartbeatSchedule;
use LIVCK\Cloud\Enums\HeartbeatScheduleType;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

it('writes a cron schedule with its time zone', function (): void {
    $schedule = HeartbeatSchedule::cron('0 3 * * *', 'Europe/Berlin');

    expect($schedule->type())->toBe(HeartbeatScheduleType::Cron)
        ->and($schedule->toArray())->toBe(['type' => 'cron', 'expression' => '0 3 * * *', 'timezone' => 'Europe/Berlin']);
});

it('leaves the time zone out when none is given', function (): void {
    expect(HeartbeatSchedule::cron('*/5 * * * *')->toArray())->toBe(['type' => 'cron', 'expression' => '*/5 * * * *']);
});

it('writes a simple period', function (): void {
    $schedule = HeartbeatSchedule::every(300);

    expect($schedule->type())->toBe(HeartbeatScheduleType::Simple)
        ->and($schedule->toArray())->toBe(['type' => 'simple', 'period_seconds' => 300]);
});

it('leaves the bounds of a period to the server', function (): void {
    // A period below the plan's shortest is raised by the server, not refused here.
    expect(HeartbeatSchedule::every(1)->toArray())->toBe(['type' => 'simple', 'period_seconds' => 1]);
});

it('refuses a blank expression or time zone', function (string $expression, ?string $timezone, string $message): void {
    expect(fn(): HeartbeatSchedule => HeartbeatSchedule::cron($expression, $timezone))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty expression' => ['', null, 'cron expression'],
    'blank expression' => ['   ', 'UTC', 'cron expression'],
    'blank time zone' => ['0 3 * * *', ' ', 'time zone'],
]);
