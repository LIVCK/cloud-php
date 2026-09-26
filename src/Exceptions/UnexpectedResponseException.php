<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

use LIVCK\Cloud\Http\Response;
use RuntimeException;

/**
 * The server answered, but not in the documented shape: a body that is not JSON, a JSON
 * value that is not an object, or a field with a type the SDK cannot map.
 *
 * Also raised when a DTO is hydrated from an array (a cache, a fixture) that does not fit;
 * {@see Response()} is null in that case.
 */
final class UnexpectedResponseException extends RuntimeException implements LivckCloudException
{
    public function __construct(string $message, private readonly ?Response $response = null)
    {
        parent::__construct($message, $response?->status() ?? 0);
    }

    public function response(): ?Response
    {
        return $this->response;
    }
}
