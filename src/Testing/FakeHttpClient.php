<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Testing;

use Closure;
use Http\Discovery\Psr17FactoryDiscovery;
use LogicException;
use PHPUnit\Framework\Assert;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A PSR-18 client for tests: answers from a queue, records every request, never
 * touches the network.
 *
 *     [$client, $http] = CloudClient::fake([
 *         MockResponse::json(['data' => $tag], 201),
 *     ]);
 *
 *     $client->tags()->ensure('customer', '4711');
 *
 *     $http->assertSent(fn (RecordedRequest $r) => $r->matches('POST', '/v1/tags/ensure')
 *         && $r->json() === ['key' => 'customer', 'value' => '4711']);
 *
 * Responses are consumed in order; a queued closure receives the PSR-7 request and
 * returns the {@see MockResponse} to answer with. Running out of responses throws, so a
 * test never silently gets more traffic than it planned for. Retries wait on the
 * {@see RecordingSleeper} returned by {@see sleeper()} instead of sleeping.
 *
 * Assertions throw {@see ExpectationFailedException}, which every test runner reports.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<MockResponse|Closure(RequestInterface): MockResponse> */
    private array $queue = [];

    /** @var list<RecordedRequest> */
    private array $recorded = [];

    private readonly RecordingSleeper $sleeper;

    private readonly ResponseFactoryInterface $responseFactory;

    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param iterable<MockResponse|Closure(RequestInterface): MockResponse> $responses
     */
    public function __construct(
        iterable $responses = [],
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->sleeper = new RecordingSleeper();
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

        foreach ($responses as $response) {
            $this->queue[] = $response;
        }
    }

    /**
     * Append responses to the queue.
     *
     * @param MockResponse|Closure(RequestInterface): MockResponse ...$responses
     */
    public function queue(MockResponse|Closure ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $recorded = RecordedRequest::fromPsr($request);
        $this->recorded[] = $recorded;

        if ($this->queue === []) {
            throw new LogicException(sprintf(
                'FakeHttpClient has no response left for %s %s (request #%d). Queue one with MockResponse.',
                $recorded->method,
                $recorded->uri,
                count($this->recorded),
            ));
        }

        $next = array_shift($this->queue);
        $mock = $next instanceof Closure ? $next($request) : $next;

        if ($mock->networkError !== null) {
            throw new FakeNetworkException($mock->networkError, $request);
        }

        $response = $this->responseFactory->createResponse($mock->status);

        foreach ($mock->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withBody($this->streamFactory->createStream($mock->body));
    }

    /**
     * Every request received, in order (retries included).
     *
     * @return list<RecordedRequest>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    public function lastRequest(): ?RecordedRequest
    {
        return $this->recorded[array_key_last($this->recorded) ?? -1] ?? null;
    }

    /** Responses still queued. */
    public function remaining(): int
    {
        return count($this->queue);
    }

    /** Where the client's retries wait; {@see RecordingSleeper::delays()} lists them. */
    public function sleeper(): RecordingSleeper
    {
        return $this->sleeper;
    }

    /**
     * Backoff waits the client would have slept, in seconds.
     *
     * @return list<float>
     */
    public function delays(): array
    {
        return $this->sleeper->delays();
    }

    /**
     * At least one recorded request satisfies the matcher.
     *
     * @param Closure(RecordedRequest): bool $matcher
     */
    public function assertSent(Closure $matcher, string $message = ''): self
    {
        foreach ($this->recorded as $request) {
            if ($matcher($request)) {
                return $this->passed();
            }
        }

        throw new ExpectationFailedException($message !== '' ? $message : sprintf(
            'No request matched the expectation. Sent: %s',
            $this->describeRecorded(),
        ));
    }

    /**
     * No recorded request satisfies the matcher.
     *
     * @param Closure(RecordedRequest): bool $matcher
     */
    public function assertNotSent(Closure $matcher, string $message = ''): self
    {
        foreach ($this->recorded as $request) {
            if ($matcher($request)) {
                throw new ExpectationFailedException($message !== '' ? $message : sprintf(
                    'A request matched an expectation that should not have been met: %s %s',
                    $request->method,
                    $request->uri,
                ));
            }
        }

        return $this->passed();
    }

    public function assertSentCount(int $count, string $message = ''): self
    {
        if (count($this->recorded) !== $count) {
            throw new ExpectationFailedException($message !== '' ? $message : sprintf(
                'Expected %d request(s), %d were sent: %s',
                $count,
                count($this->recorded),
                $this->describeRecorded(),
            ));
        }

        return $this->passed();
    }

    public function assertNothingSent(string $message = ''): self
    {
        return $this->assertSentCount(0, $message !== '' ? $message : sprintf('Expected no request, %d were sent: %s', count($this->recorded), $this->describeRecorded()));
    }

    /** Every queued response was consumed. */
    public function assertNoPendingResponses(string $message = ''): self
    {
        if ($this->queue !== []) {
            throw new ExpectationFailedException($message !== '' ? $message : sprintf('%d queued response(s) were never requested.', count($this->queue)));
        }

        return $this->passed();
    }

    /**
     * A met expectation counts as an assertion when PHPUnit runs, so a test that asserts only
     * through this fake is not reported as risky. Outside PHPUnit it does nothing.
     */
    private function passed(): self
    {
        if (class_exists(Assert::class)) {
            Assert::assertThat(true, Assert::isTrue());
        }

        return $this;
    }

    private function describeRecorded(): string
    {
        if ($this->recorded === []) {
            return '(none)';
        }

        return implode(', ', array_map(static fn(RecordedRequest $r): string => $r->method . ' ' . $r->uri, $this->recorded));
    }
}
