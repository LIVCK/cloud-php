<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\RateLimitException;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 11, the rate limit. Last on purpose: it spends the token's whole budget of 120
 * requests per minute. Without retries the budget's end is a RateLimitException carrying
 * `Retry-After`; with retries the same burst completes because the SDK waits it out.
 */
$skip = LiveApi::skipReason();

describe('scenario 11: rate limit', function () use ($skip): void {
    it('surfaces the 429 without retries and waits it out with them', function (): void {
        $journey = new Journey();
        $single = LiveApi::client(options: LiveApi::options(maxRetries: 0));
        $retrying = LiveApi::client();

        try {
            $journey->step('1 start from a fresh window', function () use ($single, $journey): void {
                try {
                    $response = $single->send(Request::get('me'));
                    $budget = $response->rateLimit();

                    expect($budget?->limit)->toBe(120);
                    $journey->note(sprintf('%d of %d requests left in the current window', $budget->remaining ?? -1, $budget->limit ?? -1));
                } catch (RateLimitException $e) {
                    $wait = ($e->retryAfter() ?? 60) + 1;
                    $journey->note(sprintf('window already spent, waiting %d s', $wait));
                    sleep($wait);
                }
            });

            $sent = $journey->step('2 a burst without retries ends in a RateLimitException with Retry-After', function () use ($single, $journey): int {
                $sent = 0;
                $started = hrtime(true);

                try {
                    for ($i = 0; $i < 200; $i++) {
                        $single->services()->list(ServiceQuery::make()->withPerPage(1));
                        $sent++;
                    }

                    expect(false)->toBeTrue('200 requests went through without a 429.');
                } catch (RateLimitException $e) {
                    $journey->note(sprintf('%d requests in %.1f s, then 429 with Retry-After %s s (remaining %d of %d)', $sent, (hrtime(true) - $started) / 1e9, $e->retryAfter() ?? 'none', $e->rateLimit()->remaining ?? -1, $e->rateLimit()->limit ?? -1));

                    expect($e->status())->toBe(429)
                        ->and($e->retryAfter())->not->toBeNull()
                        ->and($e->retryAfter())->toBeGreaterThan(0)
                        ->and($e->retryAfter())->toBeLessThanOrEqual(60)
                        ->and($e->rateLimit()?->limit)->toBe(120)
                        ->and($e->rateLimit()?->isExhausted())->toBeTrue()
                        ->and($e->rateLimit()?->resetsAt)->not->toBeNull();
                }

                return $sent;
            });

            $journey->step('3 the same burst with retries completes, waiting for Retry-After', function () use ($retrying, $sent, $journey): void {
                $mark = LiveApi::sleeper()->count();
                $burst = max($sent, 10);
                $started = hrtime(true);

                for ($i = 0; $i < $burst; $i++) {
                    $retrying->services()->list(ServiceQuery::make()->withPerPage(1));
                }

                $seconds = (hrtime(true) - $started) / 1e9;
                $waits = LiveApi::sleeper()->since($mark);
                $waited = (float) array_sum($waits);

                $journey->note(sprintf('%d requests in %.1f s, the SDK waited %d time%s for %.1f s in total', $burst, $seconds, count($waits), count($waits) === 1 ? '' : 's', $waited));

                expect($waits)->not->toBe([], 'The burst with retries never waited for a Retry-After.')
                    ->and($waited)->toBeGreaterThan(0.0)
                    ->and($seconds)->toBeGreaterThanOrEqual($waited);

                foreach ($waits as $wait) {
                    expect($wait)->toBeLessThanOrEqual(61.0);
                }
            });
        } finally {
            Scenario::report($journey);
        }
    })->skip($skip !== null, $skip ?? '');
});
