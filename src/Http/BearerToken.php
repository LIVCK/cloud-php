<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Http;

use Closure;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * The API token, held so that it cannot leak by accident.
 *
 * The value lives inside a closure, which `var_export()` prints without its bound
 * variables; {@see __debugInfo()} covers `var_dump()` and `print_r()`; serialisation is
 * refused. Nothing reads the token back except {@see authorizationHeader()}, and
 * {@see redact()} scrubs it from any text that might have picked it up (an HTTP
 * client's exception message, for instance).
 */
final readonly class BearerToken
{
    /** @var Closure(): string */
    private Closure $value;

    public function __construct(#[SensitiveParameter] string $token)
    {
        $token = trim($token);

        if ($token === '') {
            throw new InvalidArgumentException('The API token must not be empty.');
        }

        if (preg_match('/\A[\x21-\x7E]+\z/', $token) !== 1) {
            throw new InvalidArgumentException('The API token must consist of printable ASCII characters without whitespace.');
        }

        $this->value = static fn(): string => $token;
    }

    public function authorizationHeader(): string
    {
        return 'Bearer ' . ($this->value)();
    }

    /**
     * The text with every occurrence of the token replaced.
     */
    public function redact(string $text): string
    {
        return str_replace(($this->value)(), '[redacted]', $text);
    }

    /**
     * @return array{token: string}
     */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]'];
    }

    /**
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException('An API token must not be serialized. Build a new client from configuration where it is needed.');
    }
}
