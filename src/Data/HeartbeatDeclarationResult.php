<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Support\Field;
use LIVCK\Cloud\Support\Secret;
use SensitiveParameter;

/**
 * The answer to `POST /v1/heartbeats/declare`.
 *
 * - `heartbeats`: every heartbeat of the declaration that exists now, in the order declared,
 *   each with what the call did to it ({@see DeclaredHeartbeat::$result}).
 * - `notCreated`: the new keys that could not be created (plan, creation limit, switched off).
 *   The rest of the declaration took effect; this is not an error.
 * - `unlisted`: heartbeats declared earlier for the same application and environment that this
 *   declaration did not name. They are left as they were, still expecting pings: LIVCK never
 *   deletes or pauses a heartbeat because a declaration no longer names it.
 * - `skippedTags`: labels of tags that could not be attached because the organization has no
 *   room for more tags. The heartbeats exist either way.
 * - `counts`: created, updated, unchanged.
 *
 * `$raw` is the `data` object without the ping URLs.
 */
final readonly class HeartbeatDeclarationResult
{
    /**
     * @param list<DeclaredHeartbeat> $heartbeats
     * @param list<UnlistedHeartbeat> $unlisted
     * @param list<string> $skippedTags
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public array $heartbeats,
        public NotCreatedHeartbeats $notCreated,
        public array $unlisted,
        public array $skippedTags,
        public HeartbeatDeclarationCounts $counts,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data the `data` object of the response
     */
    public static function fromArray(#[SensitiveParameter] array $data): self
    {
        $heartbeats = array_map(DeclaredHeartbeat::fromArray(...), Field::objectList($data, 'heartbeats'));
        $data['heartbeats'] = array_map(static fn(DeclaredHeartbeat $heartbeat): array => $heartbeat->raw, $heartbeats);

        return new self(
            $heartbeats,
            NotCreatedHeartbeats::fromArray(Field::object($data, 'not_created')),
            array_map(UnlistedHeartbeat::fromArray(...), Field::objectList($data, 'unlisted')),
            Field::stringList($data, 'skipped_tags'),
            HeartbeatDeclarationCounts::fromArray(Field::object($data, 'counts')),
            $data,
        );
    }

    /** The heartbeat with this key (case-sensitive, as declared), or null. */
    public function heartbeat(string $key): ?DeclaredHeartbeat
    {
        foreach ($this->heartbeats as $heartbeat) {
            if ($heartbeat->key === $key) {
                return $heartbeat;
            }
        }

        return null;
    }

    /** Every heartbeat of the declaration exists now: nothing was left uncreated. */
    public function allExist(): bool
    {
        return $this->notCreated->isEmpty();
    }

    /**
     * Key to ping URL, for every heartbeat that has one, in the order declared: what a job needs
     * to report. The URLs stay {@see Secret}s; store them where secrets go. A key made of digits
     * alone becomes an integer array key, as PHP does with every such key.
     *
     * @return array<array-key, Secret>
     */
    public function pingUrls(): array
    {
        $urls = [];

        foreach ($this->heartbeats as $heartbeat) {
            if ($heartbeat->pingUrl instanceof Secret) {
                $urls[$heartbeat->key] = $heartbeat->pingUrl;
            }
        }

        return $urls;
    }

    /**
     * The keys of the heartbeats LIVCK still watches for this application and environment but
     * the declaration did not name.
     *
     * @return list<string>
     */
    public function unlistedKeys(): array
    {
        return array_map(static fn(UnlistedHeartbeat $heartbeat): string => $heartbeat->key, $this->unlisted);
    }
}
