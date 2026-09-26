<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Enums\TagSource;
use LIVCK\Cloud\Support\Field;

/**
 * An org-wide label for services: a `key:value` pair (`customer:4711`, `env:prod`) or a
 * bare key (`critical`). Keys are lowercase; values keep their case and are matched
 * exactly.
 */
final readonly class Tag
{
    /**
     * @param int|null $servicesCount how many services carry the tag; sent by every tag endpoint
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     */
    public function __construct(
        public string $id,
        public string $key,
        public ?string $value,
        public string $color,
        public string $label,
        public TagSource $source,
        public ?int $servicesCount,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'id'),
            Field::string($data, 'key'),
            Field::nullableString($data, 'value'),
            Field::string($data, 'color'),
            Field::string($data, 'label'),
            TagSource::fromApi(Field::string($data, 'source')),
            Field::nullableInt($data, 'services_count'),
            $data,
        );
    }

    public function hasValue(): bool
    {
        return $this->value !== null;
    }

    /** Maintained by the server agent; key and value cannot be changed. */
    public function isSystem(): bool
    {
        return $this->source === TagSource::System;
    }
}
