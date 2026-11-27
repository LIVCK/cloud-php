<?php

declare(strict_types=1);

use LIVCK\Cloud\Support\LoopbackHost;

it('recognises the local machine', function (string $host): void {
    expect(LoopbackHost::matches($host))->toBeTrue();
})->with(['localhost', 'LOCALHOST', 'app.localhost', '127.0.0.1', '127.42.0.1', '::1', '[::1]', '0:0:0:0:0:0:0:1']);

it('refuses every other host', function (string $host): void {
    expect(LoopbackHost::matches($host))->toBeFalse();
})->with(['ping.livck.cloud', 'localhost.example.com', '10.0.0.5', '128.0.0.1', '::2', '']);
