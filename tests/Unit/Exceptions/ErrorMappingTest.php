<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\AuthenticationException;
use LIVCK\Cloud\Exceptions\ConflictException;
use LIVCK\Cloud\Exceptions\FeatureNotAvailableException;
use LIVCK\Cloud\Exceptions\LivckCloudException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\PlanLimitException;
use LIVCK\Cloud\Exceptions\RateLimitException;
use LIVCK\Cloud\Exceptions\ServerException;
use LIVCK\Cloud\Exceptions\ServiceUnavailableException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Testing\MockResponse;

/**
 * The exception a single request answered with $response raises.
 */
function raise(MockResponse $response, string $method = 'GET', string $path = 'tags'): ApiException
{
    [$client] = singleShotClient([$response]);

    try {
        $client->request($method, $path, json: $method === 'POST' ? ['key' => 'k'] : null);
    } catch (ApiException $e) {
        return $e;
    }

    throw new LogicException('No exception was raised.');
}

describe('by status', function (): void {
    it('maps 401 with the server message', function (): void {
        $e = raise(MockResponse::error('The API token is invalid or has expired.', 401));

        expect($e)->toBeInstanceOf(AuthenticationException::class)
            ->and($e)->toBeInstanceOf(LivckCloudException::class)
            ->and($e->status())->toBe(401)
            ->and($e->getCode())->toBe(401)
            ->and($e->errorMessage())->toBe('The API token is invalid or has expired.')
            ->and($e->getMessage())->toBe('The API token is invalid or has expired. (HTTP 401, GET https://api.livck.cloud/v1/tags)')
            ->and($e->requestMethod())->toBe('GET')
            ->and($e->requestUri())->toBe('https://api.livck.cloud/v1/tags')
            ->and($e->errors())->toBe([]);
    });

    it('maps 403 without an upsell to permission denied', function (): void {
        $e = raise(MockResponse::error("This token lacks the required 'services.edit' permission.", 403));

        expect($e)->toBeInstanceOf(PermissionDeniedException::class)
            ->and($e)->not->toBeInstanceOf(FeatureNotAvailableException::class);
    });

    it('maps 404', function (): void {
        expect(raise(MockResponse::error('The requested resource was not found.', 404)))->toBeInstanceOf(NotFoundException::class);
    });

    it('maps 409 and exposes the confirmation flag', function (): void {
        $e = raise(MockResponse::error('Schedule is an escalation target: Night shift.', 409, extra: [
            'policies' => ['Night shift'],
            'requires_confirmation' => true,
        ]), 'DELETE', 'oncall/schedules/abc');

        expect($e)->toBeInstanceOf(ConflictException::class);

        assert($e instanceof ConflictException);

        expect($e->requiresConfirmation())->toBeTrue()
            ->and($e->isIdempotencyKeyInProgress())->toBeFalse()
            ->and($e->body()['policies'])->toBe(['Night shift']);

        $plain = raise(MockResponse::error('The maintenance is not in a state that allows this transition.', 409));

        assert($plain instanceof ConflictException);

        expect($plain->requiresConfirmation())->toBeFalse();
    });

    it('recognises the idempotency-in-progress 409 by its Retry-After', function (): void {
        $e = raise(MockResponse::error('A request with this key is still being processed.', 409)->withRetryAfter(1), 'POST');

        assert($e instanceof ConflictException);

        expect($e->isIdempotencyKeyInProgress())->toBeTrue();
    });

    it('maps 422 with field errors', function (): void {
        $e = raise(MockResponse::error('The key must be lowercase. (and 1 more error)', 422, [
            'key' => ['The key must be lowercase.', 'The key is reserved.'],
            'color' => ['The color must be a hex triplet.'],
        ]), 'POST');

        expect($e)->toBeInstanceOf(ValidationException::class);

        assert($e instanceof ValidationException);

        expect($e->errors())->toBe([
            'key' => ['The key must be lowercase.', 'The key is reserved.'],
            'color' => ['The color must be a hex triplet.'],
        ])
            ->and($e->firstError('key'))->toBe('The key must be lowercase.')
            ->and($e->firstError('color'))->toBe('The color must be a hex triplet.')
            ->and($e->firstError('value'))->toBeNull()
            ->and($e->firstError())->toBe('The key must be lowercase.')
            ->and($e->hasError('key'))->toBeTrue()
            ->and($e->hasError('value'))->toBeFalse()
            ->and($e->hasFieldErrors())->toBeTrue();
    });

    it('maps a 422 without field errors without crashing', function (): void {
        $e = raise(MockResponse::error('This Idempotency-Key was already used for a different request.', 422), 'POST');

        assert($e instanceof ValidationException);

        expect($e->errors())->toBe([])
            ->and($e->firstError())->toBeNull()
            ->and($e->hasFieldErrors())->toBeFalse()
            ->and($e->errorMessage())->toBe('This Idempotency-Key was already used for a different request.');
    });

    it('normalises odd error shapes to lists of strings', function (): void {
        $e = raise(MockResponse::json(['message' => 'Invalid.', 'errors' => ['name' => 'Required.', 'nested' => [1, 'kept'], 'empty' => []]], 422));

        expect($e->errors())->toBe(['name' => ['Required.'], 'nested' => ['kept']]);
    });

    it('maps 429 with retry-after and rate limit figures', function (): void {
        $e = raise(MockResponse::error('Too many requests.', 429)->withRetryAfter(7)->withRateLimit(120, 0, 784111777));

        expect($e)->toBeInstanceOf(RateLimitException::class);

        assert($e instanceof RateLimitException);

        expect($e->retryAfter())->toBe(7)
            ->and($e->rateLimit()?->limit)->toBe(120)
            ->and($e->rateLimit()?->remaining)->toBe(0)
            ->and($e->headers()['retry-after'])->toBe(['7']);
    });

    it('maps 500 and other 5xx to server errors', function (): void {
        expect(raise(MockResponse::error('Server Error', 500)))->toBeInstanceOf(ServerException::class)
            ->and(raise(MockResponse::error('Bad gateway.', 502)))->toBeInstanceOf(ServerException::class)
            ->and(raise(MockResponse::error('Timeout.', 504)))->not->toBeInstanceOf(ServiceUnavailableException::class);
    });

    it('survives a non-JSON error page from the edge', function (): void {
        $e = raise(MockResponse::raw('<html><body>502 Bad Gateway</body></html>', 502, ['Content-Type' => 'text/html']));

        expect($e)->toBeInstanceOf(ServerException::class)
            ->and($e->body())->toBe([])
            ->and($e->rawBody())->toContain('502 Bad Gateway')
            ->and($e->errorMessage())->toBe('Bad Gateway')
            ->and($e->errors())->toBe([]);
    });

    it('maps 503 as a service-unavailable server error with retry-after', function (): void {
        $e = raise(MockResponse::error('Check history is temporarily unavailable.', 503)->withRetryAfter(30));

        expect($e)->toBeInstanceOf(ServiceUnavailableException::class)
            ->and($e)->toBeInstanceOf(ServerException::class);

        assert($e instanceof ServiceUnavailableException);

        expect($e->retryAfter())->toBe(30);
    });

    it('keeps unclassified statuses on the base class', function (): void {
        expect(raise(MockResponse::error('The Idempotency-Key header must be 1 to 255 visible ASCII characters.', 400), 'POST')::class)->toBe(ApiException::class)
            ->and(raise(MockResponse::error('The HTTP method is not allowed for this endpoint.', 405))::class)->toBe(ApiException::class);
    });
});

describe('by body', function (): void {
    it('maps a limit upsell to PlanLimitException whatever the status', function (int $status): void {
        $e = raise(MockResponse::error('Plan limit reached for services.', $status, extra: [
            'limit' => 20,
            'usage' => 20,
            'upsell' => ['reason' => 'limit', 'key' => 'services'],
        ]), 'POST', 'services');

        expect($e)->toBeInstanceOf(PlanLimitException::class);

        assert($e instanceof PlanLimitException);

        expect($e->limitKey())->toBe('services')
            ->and($e->limit())->toBe(20)
            ->and($e->usage())->toBe(20)
            ->and($e->status())->toBe($status);
    })->with([402, 403, 422]);

    it('reports a limit raised deep in the domain without figures', function (): void {
        $e = raise(MockResponse::error('Plan limit exceeded: tags', 402, extra: ['upsell' => ['reason' => 'limit', 'key' => 'tags']]), 'POST');

        assert($e instanceof PlanLimitException);

        expect($e->limitKey())->toBe('tags')
            ->and($e->limit())->toBeNull()
            ->and($e->usage())->toBeNull();
    });

    it('treats a 402 without an upsell block as a plan limit with an unknown key', function (): void {
        $e = raise(MockResponse::error('Payment required.', 402), 'POST');

        assert($e instanceof PlanLimitException);

        expect($e->limitKey())->toBeNull();
    });

    it('maps a feature upsell to FeatureNotAvailableException, a permission-denied subtype', function (): void {
        $e = raise(MockResponse::error("Feature 'api_access' is not available on your current plan.", 403, extra: [
            'upsell' => ['reason' => 'feature', 'key' => 'api_access'],
        ]));

        expect($e)->toBeInstanceOf(FeatureNotAvailableException::class)
            ->and($e)->toBeInstanceOf(PermissionDeniedException::class);

        assert($e instanceof FeatureNotAvailableException);

        expect($e->featureKey())->toBe('api_access');
    });

    it('ignores an upsell block it cannot read', function (): void {
        $e = raise(MockResponse::json(['message' => 'Forbidden.', 'upsell' => 'garbage'], 403));

        expect($e::class)->toBe(PermissionDeniedException::class);
    });
});
