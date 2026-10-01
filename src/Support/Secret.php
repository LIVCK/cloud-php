<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use Closure;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * A credential the API hands out once, such as the key of a new enrollment key and the install
 * command that carries it, held with the same protection as the API token.
 *
 * The value lives inside a closure, which `var_export()` prints without its bound variables;
 * {@see __debugInfo()} covers `var_dump()` and `print_r()`; `json_encode()` sees no public
 * property; serialisation is refused, so the value never ends up in a queue payload or a cache
 * by accident. There is deliberately no `__toString()`: string interpolation fails instead of
 * printing the value, and {@see reveal()} is the one way to read it.
 */
final readonly class Secret
{
    /** @var Closure(): string */
    private Closure $value;

    public function __construct(#[SensitiveParameter] string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('A secret must not be empty.');
        }

        $this->value = static fn(): string => $value;
    }

    /** The value in plain text. Hand it on, never log it. */
    public function reveal(): string
    {
        return ($this->value)();
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /**
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException('A secret must not be serialized. Store reveal() in a secret store where it has to be kept.');
    }
}
