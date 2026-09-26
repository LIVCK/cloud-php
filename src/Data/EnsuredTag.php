<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

/**
 * The outcome of `POST /v1/tags/ensure`: the tag, and whether this call created it
 * (201) or found it already there (200, returned unchanged; a sent color is ignored
 * for an existing tag).
 */
final readonly class EnsuredTag
{
    public function __construct(
        public Tag $tag,
        public bool $created,
    ) {}

    public function wasCreated(): bool
    {
        return $this->created;
    }

    public function existed(): bool
    {
        return ! $this->created;
    }
}
