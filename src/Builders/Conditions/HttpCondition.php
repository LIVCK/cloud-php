<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Conditions on an HTTP check, one method per field of the catalog:
 *
 *     HttpCondition::statusCode()->gte(500)->down()
 *     HttpCondition::responseTimeMs()->gt(2000)->degraded()
 *     HttpCondition::body()->contains('OK')
 *     HttpCondition::json('data.status')->eq('ok')
 *     HttpCondition::header('content-type')->contains('json')
 */
final readonly class HttpCondition extends Condition
{
    /**
     * The HTTP status code the target answered with.
     *
     * @return CodeSubject<self>
     */
    public static function statusCode(): CodeSubject
    {
        return new CodeSubject('status_code', self::class);
    }

    /**
     * The whole check, in milliseconds.
     *
     * @return OrderedSubject<self>
     */
    public static function responseTimeMs(): OrderedSubject
    {
        return new OrderedSubject('response_time_ms', self::class);
    }

    /**
     * The response body as text.
     *
     * @return ContainsSubject<self>
     */
    public static function body(): ContainsSubject
    {
        return new ContainsSubject('body', self::class);
    }

    /**
     * A value in the JSON body, by dot path: `data.status`, `items.0.id`, or `items.#` for
     * an array's length.
     *
     * @return JsonSubject<self>
     */
    public static function json(string $path): JsonSubject
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException('A JSON condition needs the path into the body, e.g. data.status.');
        }

        return new JsonSubject('json.' . $path, self::class);
    }

    /**
     * A response header, matched by name case-insensitively (`content-type`, `x-cache`).
     *
     * @return StringSubject<self>
     */
    public static function header(string $name): StringSubject
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A header condition needs the header name, e.g. content-type.');
        }

        return new StringSubject('header.' . $name, self::class);
    }

    /**
     * How many redirects were followed.
     *
     * @return NumberSubject<self>
     */
    public static function redirectsFollowed(): NumberSubject
    {
        return new NumberSubject('metadata.redirects_followed', self::class);
    }

    /**
     * The response size in bytes.
     *
     * @return NumberSubject<self>
     */
    public static function contentLength(): NumberSubject
    {
        return new NumberSubject('metadata.content_length', self::class);
    }

    /**
     * The HTTP protocol the response came over (`HTTP/2.0`).
     *
     * @return StringSubject<self>
     */
    public static function protocol(): StringSubject
    {
        return new StringSubject('metadata.protocol', self::class);
    }

    /**
     * The URL after redirects.
     *
     * @return StringSubject<self>
     */
    public static function finalUrl(): StringSubject
    {
        return new StringSubject('metadata.final_url', self::class);
    }
}
