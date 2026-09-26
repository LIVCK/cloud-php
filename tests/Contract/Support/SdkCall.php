<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

use Closure;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;

/**
 * One call of the public SDK against a fake client, with the responses it needs queued.
 */
final readonly class SdkCall
{
    /**
     * @param list<MockResponse> $responses answered in order
     * @param Closure(CloudClient): mixed $call
     */
    public function __construct(
        public string $name,
        public array $responses,
        public Closure $call,
    ) {}

    /**
     * Run the call and return every request it sent.
     *
     * @return list<RecordedRequest>
     */
    public function record(): array
    {
        [$client, $http] = CloudClient::fake($this->responses);
        ($this->call)($client);
        $http->assertNoPendingResponses(sprintf('SDK call "%s" queued more responses than it used.', $this->name));

        return $http->recorded();
    }
}
