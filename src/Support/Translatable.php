<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use JsonSerializable;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * A translatable text on its way to the API (incident and maintenance titles, update
 * messages, statuspage copy).
 *
 * The API accepts either a plain string, stored under the organization's default
 * locale, or a `{locale: text}` object for multilingual content, in which case the
 * organization's default locale must be among the keys. Responses always resolve such
 * fields to ONE string, picked by the `Accept-Language` the client sent (see
 * {@see \LIVCK\Cloud\ClientOptions::$locale}), so nothing has to be parsed on the way back.
 */
final readonly class Translatable implements JsonSerializable
{
    private const string LOCALE_PATTERN = '/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*\z/';

    /**
     * @param array<string, string>|null $translations
     */
    private function __construct(
        private ?string $text,
        private ?array $translations,
    ) {}

    /**
     * One text, stored under the organization's default locale.
     */
    public static function of(string $text): self
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('A translatable text must not be blank.');
        }

        return new self($text, null);
    }

    /**
     * One text per locale. The organization's default locale must be among them, which
     * the server verifies (422 otherwise).
     *
     * @param array<string, string> $translations locale => text
     */
    public static function translations(array $translations): self
    {
        if ($translations === []) {
            throw new InvalidArgumentException('A translatable needs at least one translation.');
        }

        foreach ($translations as $locale => $text) {
            if (! is_string($locale) || preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
                throw new InvalidArgumentException(sprintf('"%s" is not a locale code (expected e.g. "de", "en", "pt-BR").', (string) $locale));
            }

            if (trim($text) === '') {
                throw new InvalidArgumentException(sprintf('The translation for "%s" must not be blank.', $locale));
            }
        }

        return new self(null, $translations);
    }

    /**
     * Accept whatever a caller has at hand: a string, a locale map or an instance.
     *
     * @param string|array<string, string>|self $value
     */
    public static function from(string|array|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? self::of($value) : self::translations($value);
    }

    public function isMultilingual(): bool
    {
        return $this->translations !== null;
    }

    /**
     * The text for a locale, or the plain text when there is only one. Null when the
     * locale is not covered.
     */
    public function text(?string $locale = null): ?string
    {
        if ($this->translations === null) {
            return $this->text;
        }

        if ($locale === null) {
            foreach ($this->translations as $first) {
                return $first;
            }
        }

        return $this->translations[$locale] ?? null;
    }

    /**
     * The texts by locale; empty for a plain text.
     *
     * @return array<string, string>
     */
    public function byLocale(): array
    {
        return $this->translations ?? [];
    }

    /**
     * @return string|array<string, string>
     */
    public function jsonSerialize(): string|array
    {
        return $this->translations ?? (string) $this->text;
    }
}
