<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

use LIVCK\Cloud\Testing\MockResponse;

/**
 * A response body the unit tests feed the SDK, tied to the operation and status it stands for.
 */
final readonly class ResponseSample
{
    /**
     * @param string $operation `METHOD /path` as the document keys it
     */
    public function __construct(
        public string $name,
        public string $operation,
        public int $status,
        public MockResponse $response,
    ) {}
}
