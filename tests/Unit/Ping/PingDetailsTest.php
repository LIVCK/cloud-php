<?php

declare(strict_types=1);

use LIVCK\Cloud\Ping\PingDetails;

it('starts empty and sends nothing', function (): void {
    $details = PingDetails::make();

    expect($details->isEmpty())->toBeTrue()
        ->and($details->toArray())->toBe([])
        ->and($details->toBody())->toBe('');
});

it('is immutable', function (): void {
    $details = PingDetails::make();
    $details->withMetric('rows', 1)->withLog('x');

    expect($details->isEmpty())->toBeTrue();
});

it('writes the duration in seconds under the conventional key', function (): void {
    expect(PingDetails::make()->withDuration(2.0)->toBody())->toBe('{"metrics":{"duration":2.0}}');
});

it('replaces a figure given twice', function (): void {
    expect(PingDetails::make()->withMetric('rows', 1)->withMetric('rows', 2)->metrics())->toBe(['rows' => 2]);
});

it('leaves out figures LIVCK would discard and lists them', function (): void {
    $details = PingDetails::make()
        ->withMetric('ok_1', 1)
        ->withMetric('Upper', 1)
        ->withMetric('with-dash', 1)
        ->withMetric('', 1)
        ->withMetric(str_repeat('a', 33), 1)
        ->withMetric('42', 1)
        ->withMetric('nan', NAN)
        ->withMetric('inf', INF)
        ->withMetric('007', 1);

    expect($details->metrics())->toBe(['ok_1' => 1, '007' => 1])
        ->and($details->droppedMetrics())->toBe(['Upper', 'with-dash', '', str_repeat('a', 33), '42', 'nan', 'inf']);
});

it('keeps at most ten figures', function (): void {
    $details = PingDetails::make();

    for ($i = 1; $i <= 12; $i++) {
        $details = $details->withMetric('m' . $i, $i);
    }

    expect($details->metrics())->toHaveCount(10)
        ->and($details->droppedMetrics())->toBe(['m11', 'm12'])
        ->and($details->withMetric('m1', 100)->metrics()['m1'] ?? null)->toBe(100);
});

it('takes a map of figures and lists entries that are not numbers', function (): void {
    $details = PingDetails::make()->withMetrics(['rows' => 3, 'ratio' => 0.5, 'name' => 'x', 'flag' => true, 7 => 1]);

    expect($details->metrics())->toBe(['rows' => 3, 'ratio' => 0.5])
        ->and($details->droppedMetrics())->toBe(['name', 'flag', '7']);
});

it('sends the log as metadata', function (): void {
    expect(PingDetails::make()->withLog("line 1\nline 2\ttab")->toArray())->toBe(['metadata' => "line 1\nline 2\ttab"]);
});

it('removes control characters and replaces invalid UTF-8', function (): void {
    $log = PingDetails::make()->withLog("a\x00b\x1Bc\x7Fd\r\n\xC3\x28")->log();

    expect($log)->toBe("abcd\r\n?(")
        ->and(preg_match('//u', (string) $log))->toBe(1);
});

it('drops a log that is empty once cleaned', function (): void {
    expect(PingDetails::make()->withLog('abc')->withLog("\x00")->log())->toBeNull();
});

it('keeps the end of a long log, cut on a character boundary', function (): void {
    // 'ä' is two bytes: an odd cut point would fall inside one.
    $log = 'START' . str_repeat('ä', 3000) . 'END';

    $kept = (string) PingDetails::make()->withLog($log)->log();

    expect(strlen($kept))->toBeLessThanOrEqual(PingDetails::MAX_LOG_BYTES)
        ->and(strlen($kept))->toBeGreaterThan(PingDetails::MAX_LOG_BYTES - 4)
        ->and($kept)->toEndWith('END')
        ->and($kept)->not->toContain('START')
        ->and(preg_match('//u', $kept))->toBe(1);
});

it('keeps a four-byte character whole at the cut', function (): void {
    $kept = (string) PingDetails::make()->withLog(str_repeat('😀', 1500))->log();

    expect(strlen($kept))->toBe(4096)
        ->and(preg_match('//u', $kept))->toBe(1);

    $kept = (string) PingDetails::make()->withLog('x' . str_repeat('😀', 1500))->log();

    expect(strlen($kept) % 4)->toBe(0)
        ->and(preg_match('//u', $kept))->toBe(1);
});

it('keeps a log shorter than the limit as it is', function (): void {
    $log = str_repeat('a', PingDetails::MAX_LOG_BYTES);

    expect(PingDetails::make()->withLog($log)->log())->toBe($log);
});

it('shortens the log further when its escapes would pass the body limit', function (): void {
    // Each quote and line break doubles in JSON: 4096 bytes of them are 8192 bytes encoded.
    $details = PingDetails::make()->withMetric('rows', 1)->withLog(str_repeat("\"\n", 2048) . 'END');

    $body = $details->toBody();
    $decoded = json_decode($body, true);

    expect(strlen($body))->toBeLessThanOrEqual(PingDetails::MAX_BODY_BYTES)
        ->and(strlen($body))->toBeGreaterThan(PingDetails::MAX_BODY_BYTES / 2)
        ->and($decoded)->toBeArray()
        ->and(is_array($decoded) ? $decoded['metrics'] : null)->toBe(['rows' => 1])
        ->and(is_array($decoded) && is_string($decoded['metadata']) ? $decoded['metadata'] : '')->toEndWith('END');
});

it('writes slashes and non-ASCII text unescaped', function (): void {
    expect(PingDetails::make()->withLog('/var/backup ✓ fertig')->toBody())->toBe('{"metadata":"/var/backup ✓ fertig"}');
});
