<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Integration\Support;

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Http\Response;
use RuntimeException;
use SensitiveParameter;

/**
 * The live API the integration suite runs against, configured through the environment.
 *
 * `LIVCK_CLOUD_TOKEN` is the token every test needs; without it the suite is skipped.
 * `LIVCK_CLOUD_BASE_URI` points at a dev stack (production unless set). The scenario suite
 * additionally reads the tokens of a read-only key, a key without the delete abilities for
 * incidents and maintenances, a key of a second organization and a key of an organization
 * whose plan has no API access; a scenario that needs one of them names it in its skip
 * condition.
 *
 * Every client built here shares one {@see WaitLog} (every retry wait the SDK performs) and
 * one {@see AttemptLog} (every attempt the transport logs), so a scenario can prove that a
 * client-side refusal sent nothing and can report how long the SDK waited for the server.
 */
final class LiveApi
{
    public const string TOKEN_VARIABLE = 'LIVCK_CLOUD_TOKEN';

    public const string READONLY_TOKEN_VARIABLE = 'LIVCK_CLOUD_TOKEN_READONLY';

    public const string NO_INCIDENT_DELETE_TOKEN_VARIABLE = 'LIVCK_CLOUD_TOKEN_NO_INCIDENT_DELETE';

    public const string OTHER_ORG_TOKEN_VARIABLE = 'LIVCK_CLOUD_TOKEN_OTHER_ORG';

    public const string NO_API_TOKEN_VARIABLE = 'LIVCK_CLOUD_TOKEN_NO_API';

    public const string BASE_URI_VARIABLE = 'LIVCK_CLOUD_BASE_URI';

    private static ?string $runPrefix = null;

    private static ?WaitLog $sleeper = null;

    private static ?AttemptLog $attempts = null;

    /**
     * Why the suite cannot run, or null when it can: the first of the given token variables
     * that is missing (the main token when none is named).
     */
    public static function skipReason(string ...$variables): ?string
    {
        foreach ($variables === [] ? [self::TOKEN_VARIABLE] : $variables as $variable) {
            if (self::token($variable) === null) {
                return sprintf(
                    'Integration tests run against a live API only: set %s (and %s for anything but production).',
                    $variable,
                    self::BASE_URI_VARIABLE,
                );
            }
        }

        return null;
    }

    /**
     * A client for one of the environment's tokens, with the suite's retry settings unless
     * options are given.
     */
    public static function client(string $variable = self::TOKEN_VARIABLE, ?ClientOptions $options = null): CloudClient
    {
        $token = self::token($variable) ?? throw new RuntimeException(sprintf('%s is not set.', $variable));

        return self::clientWithToken($token, $options);
    }

    /** A client for a token that is not in the environment (a bogus one, for the 401 scenario). */
    public static function clientWithToken(#[SensitiveParameter] string $token, ?ClientOptions $options = null): CloudClient
    {
        return new CloudClient($token, $options ?? self::options(), sleeper: self::sleeper());
    }

    /**
     * The suite's options: the base URI from the environment, generous retries so that the
     * per-token budget (120 requests per minute) only slows a scenario down instead of
     * failing it, and a `Retry-After` ceiling just above the budget's window.
     */
    public static function options(int $maxRetries = 3, int $maxRetryAfter = 61): ClientOptions
    {
        return new ClientOptions(
            baseUri: self::baseUri(),
            timeout: 30.0,
            maxRetries: $maxRetries,
            maxRetryAfter: $maxRetryAfter,
            userAgentSuffix: 'integration-suite',
            logger: self::attempts(),
        );
    }

    public static function baseUri(): string
    {
        $uri = getenv(self::BASE_URI_VARIABLE);

        return is_string($uri) && trim($uri) !== '' ? trim($uri) : ClientOptions::DEFAULT_BASE_URI;
    }

    /** Every wait the SDK's transport performed, across all clients of this process. */
    public static function sleeper(): WaitLog
    {
        return self::$sleeper ??= new WaitLog();
    }

    /** Every attempt the SDK's transport logged, across all clients of this process. */
    public static function attempts(): AttemptLog
    {
        return self::$attempts ??= new AttemptLog();
    }

    /**
     * One prefix per process for everything the run creates (`sdkit-3f9a1c2b`): lowercase,
     * usable as a tag key, a page slug and a DNS label, and easy to find in a cleanup query.
     */
    public static function runPrefix(): string
    {
        return self::$runPrefix ??= 'sdkit-' . bin2hex(random_bytes(4));
    }

    /**
     * The `data` object of a response envelope.
     *
     * @return array<string, mixed>
     */
    public static function data(Response $response): array
    {
        $data = $response->json()['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException(sprintf('%s %s answered without a data object.', $response->requestMethod(), $response->requestUri()));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private static function token(string $variable): ?string
    {
        $token = getenv($variable);

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }
}
