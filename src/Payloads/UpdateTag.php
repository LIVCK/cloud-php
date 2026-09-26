<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Payloads;

use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * A partial update for `PATCH /v1/tags/{id}`: only what was set is sent, everything
 * else stays as it is. Renaming keeps the id stable, every tagged service follows.
 *
 *     UpdateTag::make()->withValue('4712')->withColor('#22c55e')
 *
 * A system tag (agent-derived) accepts a new color only; renaming it is a 422.
 */
final readonly class UpdateTag
{
    /**
     * @param array<string, string|null> $changes
     */
    private function __construct(
        private array $changes = [],
    ) {}

    public static function make(): self
    {
        return new self();
    }

    /** The new key: lowercase, `a-z0-9` with inner `_ . -`, 1 to 50 characters. */
    public function withKey(string $key): self
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A tag key must not be blank.');
        }

        return new self([...$this->changes, 'key' => $key]);
    }

    /** The new value; null (or an empty string) turns the tag into a bare key. */
    public function withValue(?string $value): self
    {
        return new self([...$this->changes, 'value' => $value]);
    }

    /** Drop the value, keeping just the key. */
    public function withoutValue(): self
    {
        return $this->withValue(null);
    }

    /** `#RRGGBB`. */
    public function withColor(string $color): self
    {
        if (preg_match('/\A#[0-9A-Fa-f]{6}\z/', $color) !== 1) {
            throw new InvalidArgumentException('A tag color is a hex triplet such as #22c55e.');
        }

        return new self([...$this->changes, 'color' => $color]);
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return $this->changes;
    }
}
