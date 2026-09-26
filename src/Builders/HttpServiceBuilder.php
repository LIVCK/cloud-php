<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use LIVCK\Cloud\Builders\Concerns\ConfiguresIpVersion;
use LIVCK\Cloud\Builders\Conditions\CustomCondition;
use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Enums\HttpMethod;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\JsonObject;

/**
 * An HTTP(S) check. Without conditions the server applies its default (status code 400
 * or above is down).
 *
 * Header values and auth secrets are write-only: they are encrypted at rest and read
 * back as the keep sentinel. On a create every secret is sent in full.
 */
final class HttpServiceBuilder extends MonitoredServiceBuilder
{
    use ConfiguresIpVersion;

    private const string HEADER_NAME_PATTERN = '/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/';

    /** The request method (`GET` by default). */
    public function method(HttpMethod $method): static
    {
        return $this->withConfigValue('method', self::wireValue($method));
    }

    /**
     * The request headers, replacing any set before. Sent as a JSON object even when empty.
     *
     * @param array<string, string> $headers name => value
     */
    public function headers(array $headers): static
    {
        $map = [];

        foreach ($headers as $name => $value) {
            $map[$this->headerName($name)] = $this->headerValue($name, $value);
        }

        return $this->withConfigValue('headers', JsonObject::of($map));
    }

    /** One request header, added to those set before (a same-named one is replaced). */
    public function header(string $name, string $value): static
    {
        $current = $this->config['headers'] ?? null;
        $map = $current instanceof JsonObject ? $current->toArray() : [];
        $map[$this->headerName($name)] = $this->headerValue($name, $value);

        return $this->withConfigValue('headers', JsonObject::of($map));
    }

    /** How the check authenticates; see {@see HttpAuth}. */
    public function auth(HttpAuth $auth): static
    {
        return $this->withConfigValue('auth', $auth->toArray());
    }

    /** The request body, for `POST`, `PUT` and `PATCH` (at most 10,000 characters). */
    public function body(string $body): static
    {
        return $this->withConfigValue('body', $body);
    }

    /** Follow 3xx redirects (the default); false checks the first response only. */
    public function followRedirects(bool $follow = true): static
    {
        return $this->withConfigValue('follow_redirects', $follow);
    }

    /** Verify the certificate (the default); false accepts self-signed ones. */
    public function verifySsl(bool $verify = true): static
    {
        return $this->withConfigValue('verify_ssl', $verify);
    }

    /**
     * Conditions on the response; see {@see HttpCondition}. Appended to those set before.
     */
    public function condition(HttpCondition|CustomCondition ...$conditions): static
    {
        return $this->withConditions(...$conditions);
    }

    private function headerName(string $name): string
    {
        if (preg_match(self::HEADER_NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid header name.', $name));
        }

        return $name;
    }

    private function headerValue(string $name, string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The %s header value must not contain line breaks.', $name));
        }

        return $value;
    }
}
