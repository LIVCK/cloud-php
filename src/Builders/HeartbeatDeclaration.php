<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Every heartbeat one application expects in one environment, for `POST /v1/heartbeats/declare`:
 *
 *     HeartbeatDeclaration::of('shop', 'production',
 *         HeartbeatBuilder::schedule('export:nightly', HeartbeatSchedule::cron('0 3 * * *', 'Europe/Berlin')),
 *         HeartbeatBuilder::queue('queue:default', HeartbeatSchedule::every(60)),
 *     );
 *
 * LIVCK finds each heartbeat again by application, environment and key. Send the whole list each
 * time (on every deploy, say): new keys are created, changed ones are updated, the rest is left
 * alone. A heartbeat that is missing from the list is not removed or paused; the answer names it
 * under `unlisted`. An empty list is allowed and changes nothing.
 *
 * Immutable: {@see add()} returns a new declaration. The server checks the names (lowercase
 * letters, digits, `.`, `_`, `-`) and the size of the list.
 */
final readonly class HeartbeatDeclaration
{
    /**
     * @param array<string, HeartbeatBuilder> $heartbeats keyed by heartbeat key, in the order added
     */
    private function __construct(
        private string $app,
        private string $environment,
        private array $heartbeats,
    ) {}

    /**
     * @param string $app the application the heartbeats belong to, e.g. `shop`
     * @param string $environment where it runs, e.g. `production`; a declaration only ever
     *                            touches heartbeats of its own environment
     *
     * @throws InvalidArgumentException for a blank application or environment, or a key declared twice
     */
    public static function of(string $app, string $environment, HeartbeatBuilder ...$heartbeats): self
    {
        if (trim($app) === '') {
            throw new InvalidArgumentException('The application of a heartbeat declaration must not be blank.');
        }

        if (trim($environment) === '') {
            throw new InvalidArgumentException('The environment of a heartbeat declaration must not be blank.');
        }

        return (new self($app, $environment, []))->add(...$heartbeats);
    }

    /**
     * The same declaration with more heartbeats.
     *
     * @throws InvalidArgumentException for a key that is already declared
     */
    public function add(HeartbeatBuilder ...$heartbeats): self
    {
        $list = $this->heartbeats;

        foreach ($heartbeats as $heartbeat) {
            $key = $heartbeat->key();

            if (array_key_exists($key, $list)) {
                throw new InvalidArgumentException(sprintf('The heartbeat key "%s" is declared twice; each key may appear once.', $key));
            }

            $list[$key] = $heartbeat;
        }

        return new self($this->app, $this->environment, $list);
    }

    public function app(): string
    {
        return $this->app;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    /**
     * The declared keys, in the order added.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn(HeartbeatBuilder $heartbeat): string => $heartbeat->key(), array_values($this->heartbeats));
    }

    /**
     * @return array{app: string, environment: string, heartbeats: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'app' => $this->app,
            'environment' => $this->environment,
            'heartbeats' => array_map(static fn(HeartbeatBuilder $heartbeat): array => $heartbeat->toArray(), array_values($this->heartbeats)),
        ];
    }
}
