<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\Field;
use LIVCK\Cloud\Support\Secret;
use SensitiveParameter;

/**
 * The outcome of `POST /v1/enrollment-keys`: the new key, and the only chance to read its
 * value. The server keeps a hash; nothing returns the key again, so hand it on (or the install
 * command, which carries it) right away, and create a new key whenever another server is to be
 * added.
 *
 * `token` and `installCommand` are {@see Secret}s: `reveal()` reads them, every dump, log line
 * and `json_encode()` shows them redacted. `$key->raw` holds the rest of the payload, without
 * them.
 */
final readonly class CreatedEnrollmentKey
{
    /**
     * @param Secret $token the key (`lve_…`), as `Authorization: Bearer` for the installer
     * @param Secret $installCommand the one-liner to run on the server, as root: installs the agent and enrolls it
     *                               with this key
     */
    public function __construct(
        public EnrollmentKey $key,
        public Secret $token,
        public Secret $installCommand,
    ) {}

    /**
     * @param array<string, mixed> $data the `data` object of the response
     */
    public static function fromArray(#[SensitiveParameter] array $data): self
    {
        $token = Field::string($data, 'token');
        $installCommand = Field::string($data, 'install_command');

        if ($token === '' || $installCommand === '') {
            throw new UnexpectedResponseException('A created enrollment key came without its key or install command.');
        }

        unset($data['token'], $data['install_command']);

        return new self(EnrollmentKey::fromArray(self::withoutKey($data, $token)), new Secret($token), new Secret($installCommand));
    }

    /**
     * The payload with every other text that carries the key redacted, so that a field the
     * server adds later (another install command, say) cannot leak it through `$key->raw`.
     *
     * @template K of array-key
     *
     * @param array<K, mixed> $data
     * @return array<K, mixed>
     */
    private static function withoutKey(#[SensitiveParameter] array $data, #[SensitiveParameter] string $token): array
    {
        foreach ($data as $field => $value) {
            if (is_string($value) && str_contains($value, $token)) {
                $data[$field] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$field] = self::withoutKey($value, $token);
            }
        }

        return $data;
    }
}
