<?php

declare(strict_types=1);

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Http\RetryPolicy;

describe('transport errors', function (): void {
    it('retries every method except a POST without a key and a PATCH with idempotency off', function (string $method, bool $hasKey, bool $idempotencyEnabled, bool $expected): void {
        $policy = new RetryPolicy(idempotencyEnabled: $idempotencyEnabled);

        expect($policy->retriesTransportError($method, $hasKey))->toBe($expected);
    })->with([
        'GET' => ['GET', false, true, true],
        'HEAD' => ['HEAD', false, true, true],
        'PUT' => ['PUT', false, true, true],
        'DELETE' => ['DELETE', false, true, true],
        'POST with key' => ['POST', true, true, true],
        'POST without key' => ['POST', false, true, false],
        'POST, idempotency off' => ['POST', false, false, false],
        'PATCH, idempotency on' => ['PATCH', false, true, true],
        'PATCH, idempotency off' => ['PATCH', false, false, false],
        'GET, idempotency off' => ['GET', false, false, true],
    ]);
});

describe('statuses', function (): void {
    it('retries 429 always, 409 only while an idempotent request is in progress, gateway errors for reads and idempotent methods', function (int $status, string $method, bool $inProgress, bool $expected): void {
        expect((new RetryPolicy())->retriesStatus($status, $method, $inProgress))->toBe($expected);
    })->with([
        '429 GET' => [429, 'GET', false, true],
        '429 POST' => [429, 'POST', false, true],
        '429 PATCH' => [429, 'PATCH', false, true],
        '409 POST in progress' => [409, 'POST', true, true],
        '409 POST state conflict' => [409, 'POST', false, false],
        '409 GET' => [409, 'GET', false, false],
        '502 GET' => [502, 'GET', false, true],
        '503 HEAD' => [503, 'HEAD', false, true],
        '504 PUT' => [504, 'PUT', false, true],
        '503 DELETE' => [503, 'DELETE', false, true],
        '503 OPTIONS' => [503, 'OPTIONS', false, true],
        '502 POST' => [502, 'POST', false, false],
        '503 POST' => [503, 'POST', false, false],
        '504 PATCH' => [504, 'PATCH', false, false],
        '500 GET' => [500, 'GET', false, false],
        '501 GET' => [501, 'GET', false, false],
        '422 GET' => [422, 'GET', false, false],
        '404 DELETE' => [404, 'DELETE', false, false],
        '401 GET' => [401, 'GET', false, false],
    ]);
});

describe('delays', function (): void {
    it('waits exactly Retry-After when it is within the cap, at least a second, and fails fast beyond it', function (): void {
        $policy = new RetryPolicy(maxRetryAfter: 60);

        // A Retry-After of 0 is a truncated "less than a second", never "now".
        expect($policy->delay(1, 30))->toBe(30.0)
            ->and($policy->delay(1, 60))->toBe(60.0)
            ->and($policy->delay(1, 61))->toBeNull()
            ->and($policy->delay(1, 1))->toBe(1.0)
            ->and($policy->delay(1, 0))->toBe(1.0);
    });

    it('backs off exponentially with full jitter, capped', function (): void {
        $policy = new RetryPolicy(backoffBase: 0.5, backoffCap: 4.0, random: static fn(): float => 1.0);
        $zero = new RetryPolicy(backoffBase: 0.5, backoffCap: 4.0, random: static fn(): float => 0.0);

        expect($policy->backoff(1))->toBe(0.5)
            ->and($policy->backoff(2))->toBe(1.0)
            ->and($policy->backoff(3))->toBe(2.0)
            ->and($policy->backoff(4))->toBe(4.0)
            ->and($policy->backoff(9))->toBe(4.0)
            ->and($zero->backoff(3))->toBe(0.0)
            ->and($policy->delay(2, null))->toBe(1.0);
    });

    it('draws jitter from [0, ceiling) by default', function (): void {
        $policy = new RetryPolicy(backoffBase: 1.0, backoffCap: 8.0);

        foreach (range(1, 50) as $ignored) {
            $delay = $policy->backoff(2);

            expect($delay)->toBeGreaterThanOrEqual(0.0)->toBeLessThan(2.0);
        }
    });
});

it('is built from client options', function (): void {
    $policy = RetryPolicy::fromOptions(new ClientOptions(maxRetries: 4, backoffBase: 1.0, backoffCap: 2.0, maxRetryAfter: 10, idempotency: false));

    expect($policy->maxRetries)->toBe(4)
        ->and($policy->backoffBase)->toBe(1.0)
        ->and($policy->backoffCap)->toBe(2.0)
        ->and($policy->maxRetryAfter)->toBe(10)
        ->and($policy->idempotencyEnabled)->toBeFalse();
});

it('validates its parameters', function (): void {
    expect(fn(): RetryPolicy => new RetryPolicy(maxRetries: -1))->toThrow(InvalidArgumentException::class)
        ->and(fn(): RetryPolicy => new RetryPolicy(backoffBase: 2.0, backoffCap: 1.0))->toThrow(InvalidArgumentException::class)
        ->and(fn(): RetryPolicy => new RetryPolicy(maxRetryAfter: -1))->toThrow(InvalidArgumentException::class);
});
