<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use LIVCK\Cloud\Enums\HttpAuthType;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\KeepSecret;

/**
 * The `config.auth` block of an HTTP check, in the shape the catalog's `auth` field takes.
 *
 *     HttpAuth::bearer($token)
 *     HttpAuth::basic('monitor', $password)
 *     HttpAuth::apiKey('X-Api-Key', $key)
 *     HttpAuth::none()
 *
 * The secret part (token, password, key value) is write-only: it is encrypted at rest and
 * reads back as the keep sentinel. On an update, {@see KeepSecret::keep()} in its place
 * leaves the stored secret untouched; on a create every secret has to be sent in full.
 */
final readonly class HttpAuth
{
    /**
     * @param array<string, string|KeepSecret> $fields
     */
    private function __construct(
        private HttpAuthType $type,
        private array $fields,
    ) {}

    public static function none(): self
    {
        return new self(HttpAuthType::None, []);
    }

    public static function bearer(string|KeepSecret $token): self
    {
        return new self(HttpAuthType::Bearer, ['token' => self::secret($token, 'token')]);
    }

    public static function basic(string $username, string|KeepSecret $password): self
    {
        if (trim($username) === '') {
            throw new InvalidArgumentException('A basic-auth username must not be blank.');
        }

        return new self(HttpAuthType::Basic, ['username' => $username, 'password' => self::secret($password, 'password')]);
    }

    /** A header carrying an API key, e.g. `X-Api-Key`. */
    public static function apiKey(string $header, string|KeepSecret $value): self
    {
        if (trim($header) === '') {
            throw new InvalidArgumentException('An API-key header name must not be blank.');
        }

        return new self(HttpAuthType::ApiKey, ['header' => $header, 'value' => self::secret($value, 'value')]);
    }

    public function type(): HttpAuthType
    {
        return $this->type;
    }

    /**
     * The wire form: `type` plus the fields of that type.
     *
     * @return array<string, string|KeepSecret>
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, ...$this->fields];
    }

    private static function secret(string|KeepSecret $secret, string $field): string|KeepSecret
    {
        if (is_string($secret) && $secret === '') {
            throw new InvalidArgumentException(sprintf('The auth %s must not be empty; pass KeepSecret::keep() on an update to leave the stored one untouched.', $field));
        }

        return $secret;
    }
}
