<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\UptimeDay;
use LIVCK\Cloud\Enums\UptimeDayStatus;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;

it('reads the day as a calendar day and the figure as reported', function (): void {
    $day = UptimeDay::fromArray(['date' => '2026-09-25', 'status' => 'up', 'uptime_percent' => 99.9954, 'incidents' => 0]);

    expect($day->date->year)->toBe(2026)
        ->and($day->date->month)->toBe(9)
        ->and($day->date->day)->toBe(25)
        ->and($day->status)->toBe(UptimeDayStatus::Up)
        ->and($day->uptimePercent)->toBe(99.9954)
        ->and($day->uptime())->toBe(99.9954)
        ->and($day->incidents)->toBe(0)
        ->and($day->hasData())->toBeTrue();
});

it('never mistakes a day without data for an outage', function (): void {
    $day = UptimeDay::fromArray(['date' => '2026-09-24', 'status' => 'no_data', 'uptime_percent' => 0, 'incidents' => 0]);

    expect($day->hasData())->toBeFalse()
        ->and($day->uptimePercent)->toBe(0.0)
        ->and($day->uptime())->toBeNull();

    $outage = UptimeDay::fromArray(['date' => '2026-09-24', 'status' => 'down', 'uptime_percent' => 0, 'incidents' => 2]);

    expect($outage->uptime())->toBe(0.0)
        ->and($outage->status)->toBe(UptimeDayStatus::Down);
});

it('keeps an unknown status readable and treats it as measured', function (): void {
    $day = UptimeDay::fromArray(['date' => '2026-09-24', 'status' => 'partial', 'uptime_percent' => 50, 'incidents' => 1]);

    expect($day->status)->toBe(UptimeDayStatus::Unrecognized)
        ->and($day->uptime())->toBe(50.0);
});

it('refuses an instant where a calendar day is documented', function (): void {
    expect(fn(): UptimeDay => UptimeDay::fromArray(['date' => '2026-09-24T00:00:00Z', 'status' => 'up', 'uptime_percent' => 100, 'incidents' => 0]))
        ->toThrow(UnexpectedResponseException::class, 'calendar day');
});
