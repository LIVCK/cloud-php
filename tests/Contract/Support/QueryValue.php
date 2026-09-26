<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

/**
 * One query parameter as it went over the wire: `probe[]=a&probe[]=b` is one value with
 * the base name `probe` and two entries.
 */
final readonly class QueryValue
{
    /**
     * @param string $name the name as sent, e.g. `probe[]`
     * @param list<string> $values decoded, in order
     */
    public function __construct(
        public string $name,
        public string $baseName,
        public bool $isList,
        public array $values,
    ) {}

    public function scalar(): string
    {
        return $this->values[0] ?? '';
    }
}
