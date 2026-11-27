<?php

declare(strict_types=1);

use LIVCK\Cloud\Ping\PingResult;

it('is ok for a 2xx only', function (int $status, bool $ok): void {
    expect(PingResult::fromStatus($status)->ok())->toBe($ok);
})->with([[200, true], [204, true], [301, false], [404, false], [429, false], [500, false]]);

it('has no error for an accepted ping', function (): void {
    $result = PingResult::fromStatus(200, ['Rows']);

    expect($result->sent)->toBeTrue()
        ->and($result->status)->toBe(200)
        ->and($result->unknownUrl)->toBeFalse()
        ->and($result->error)->toBeNull()
        ->and($result->droppedMetrics)->toBe(['Rows']);
});

it('marks only a 404 as an unknown URL', function (): void {
    expect(PingResult::fromStatus(404)->unknownUrl)->toBeTrue()
        ->and(PingResult::fromStatus(410)->unknownUrl)->toBeFalse()
        ->and(PingResult::fromStatus(410)->error)->toBe('LIVCK refused the ping (HTTP 410).');
});

it('describes a ping that got no response', function (): void {
    $result = PingResult::noResponse('No response: timed out', ['Rows']);

    expect($result->sent)->toBeFalse()
        ->and($result->noResponse)->toBeTrue()
        ->and($result->status)->toBeNull()
        ->and($result->ok())->toBeFalse()
        ->and($result->unknownUrl)->toBeFalse()
        ->and($result->error)->toBe('No response: timed out')
        ->and($result->droppedMetrics)->toBe(['Rows']);
});

it('tells a ping that was never sent from one without an answer', function (): void {
    $result = PingResult::notSent('The ping URL is empty.');

    expect($result->sent)->toBeFalse()
        ->and($result->noResponse)->toBeFalse()
        ->and($result->status)->toBeNull();
});

it('never flags an HTTP answer as no response', function (int $status): void {
    expect(PingResult::fromStatus($status)->noResponse)->toBeFalse();
})->with([200, 404, 429, 500, 503]);

it('keeps the positional constructor of earlier releases', function (): void {
    $result = new PingResult(false, null, false, 'x', []);

    expect($result->noResponse)->toBeFalse();
});
