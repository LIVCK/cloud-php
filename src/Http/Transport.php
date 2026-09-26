<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Http;

use Http\Message\MultipartStream\MultipartStreamBuilder;
use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\TransportException;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\Json;
use LIVCK\Cloud\Support\Uuid;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Turns a {@see Request} into a PSR-7 message, sends it through the PSR-18 client and
 * maps what comes back: a {@see Response} for 2xx, a typed exception otherwise.
 *
 * Owns everything that is the same for every call: the Authorization header, the
 * User-Agent, `Accept-Language`, the Idempotency-Key (one UUID per logical call, reused
 * for every retry of that call), the retry loop with its backoff and the per-attempt
 * debug log line. Stateless between calls, so one instance serves a whole worker.
 *
 * One special case in the loop: a DELETE that is sent again (after a transport error or
 * a gateway error) may find its resource gone because the first attempt did go through.
 * That 404 is the success it was retried for and is returned as such. A 404 on the first
 * attempt stays a {@see \LIVCK\Cloud\Exceptions\NotFoundException}.
 */
final readonly class Transport
{
    public function __construct(
        private BearerToken $token,
        private ClientOptions $options,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private RetryPolicy $retryPolicy,
        private Sleeper $sleeper,
        private string $userAgent,
    ) {}

    /**
     * @throws TransportException when no response could be obtained
     * @throws ApiException for a 4xx/5xx response
     * @throws UnexpectedResponseException for a redirect (never followed)
     */
    public function send(Request $request): Response
    {
        $idempotencyKey = $this->idempotencyKeyFor($request);
        $uri = $this->uriFor($request);
        $message = $this->messageFor($request, $uri, $idempotencyKey);
        $maxAttempts = $this->retryPolicy->maxRetries + 1;

        for ($attempt = 1; ; $attempt++) {
            if ($attempt > 1 && $message->getBody()->isSeekable()) {
                $message->getBody()->rewind();
            }

            $started = hrtime(true);

            try {
                $psrResponse = $this->httpClient->sendRequest($message);
            } catch (ClientExceptionInterface $e) {
                $this->log($request->method, $uri, null, $started, $attempt, $maxAttempts, false, $idempotencyKey);

                $retry = $attempt < $maxAttempts
                    && $this->retryPolicy->retriesTransportError($request->method, $idempotencyKey !== null)
                    && $this->canResend($message);

                if (! $retry) {
                    throw new TransportException($this->token->redact($e->getMessage()), $request->method, $uri, $e::class, $attempt);
                }

                $this->sleeper->sleep($this->retryPolicy->backoff($attempt));

                continue;
            }

            $response = Response::fromPsr($psrResponse, $request->method, $uri);
            $this->log($request->method, $uri, $response->status(), $started, $attempt, $maxAttempts, $response->wasReplayed(), $idempotencyKey);

            if ($response->status() < 300) {
                return $response;
            }

            if ($response->status() < 400) {
                throw new UnexpectedResponseException(sprintf(
                    'Unexpected redirect (HTTP %d) to %s; the API never redirects and the client does not follow. (%s %s)',
                    $response->status(),
                    $response->header('Location') ?? '<no Location>',
                    $request->method,
                    $uri,
                ), $response);
            }

            if ($response->status() === 404 && $attempt > 1 && $request->method === 'DELETE') {
                return $response;
            }

            $delay = $this->retryDelayFor($response, $request->method, $idempotencyKey !== null, $attempt);

            if ($attempt < $maxAttempts && $delay !== null && $this->canResend($message)) {
                $this->sleeper->sleep($delay);

                continue;
            }

            throw ApiException::fromResponse($response);
        }
    }

    private function idempotencyKeyFor(Request $request): ?string
    {
        if ($request->method !== 'POST') {
            return null;
        }

        if ($request->idempotencyKey !== null) {
            return $request->idempotencyKey;
        }

        return $request->autoIdempotencyKey && $this->options->idempotency ? Uuid::v4() : null;
    }

    private function uriFor(Request $request): string
    {
        return $this->options->baseUri . '/' . $request->target();
    }

    private function messageFor(Request $request, string $uri, ?string $idempotencyKey): RequestInterface
    {
        $message = $this->requestFactory->createRequest($request->method, $uri)
            ->withHeader('Authorization', $this->token->authorizationHeader())
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->userAgent);

        if ($this->options->locale !== null) {
            $message = $message->withHeader('Accept-Language', $this->options->locale);
        }

        foreach ($request->headers as $name => $value) {
            $message = $message->withHeader($name, $value);
        }

        if ($idempotencyKey !== null) {
            $message = $message->withHeader('Idempotency-Key', $idempotencyKey);
        }

        if ($request->json !== null) {
            return $message
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(Json::encode($request->json)));
        }

        if ($request->multipart !== []) {
            $builder = new MultipartStreamBuilder($this->streamFactory);

            foreach ($request->multipart as $part) {
                $options = [];

                if ($part->filename !== null) {
                    $options['filename'] = $part->filename;
                }

                if ($part->contentType !== null) {
                    $options['headers'] = ['Content-Type' => $part->contentType];
                }

                $builder->addResource($part->name, $part->contents, $options);
            }

            return $message
                ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $builder->getBoundary())
                ->withBody($builder->build());
        }

        return $message;
    }

    /**
     * Seconds to wait before sending again, or null when the response is final.
     *
     * The idempotency-in-progress 409 is the only 409 of the API with a `Retry-After`;
     * it is recognised by that header plus the key the request carried, never by text.
     */
    private function retryDelayFor(Response $response, string $method, bool $hasIdempotencyKey, int $retry): ?float
    {
        $status = $response->status();
        $retryAfter = $response->retryAfter();
        $idempotencyKeyInProgress = $status === 409 && $hasIdempotencyKey && $retryAfter !== null;

        if (! $this->retryPolicy->retriesStatus($status, $method, $idempotencyKeyInProgress)) {
            return null;
        }

        return $this->retryPolicy->delay($retry, $retryAfter);
    }

    /**
     * A body can only be sent again when it can be rewound; a one-shot stream (a
     * `php://input`, a pipe) goes out once.
     */
    private function canResend(RequestInterface $message): bool
    {
        $body = $message->getBody();

        return $body->getSize() === 0 || $body->isSeekable();
    }

    /**
     * One debug line per attempt. Never the Authorization header, never a body.
     */
    private function log(string $method, string $uri, ?int $status, int $startedAt, int $attempt, int $maxAttempts, bool $replayed, ?string $idempotencyKey): void
    {
        $this->options->logger->debug('{method} {uri} -> {status} in {duration_ms} ms (attempt {attempt}/{max_attempts})', [
            'method' => $method,
            'uri' => $uri,
            'status' => $status ?? 'transport error',
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 1),
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'replayed' => $replayed,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
