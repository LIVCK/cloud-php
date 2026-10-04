<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Payloads;

use LIVCK\Cloud\Enums\AccessType;
use LIVCK\Cloud\Enums\LogoSize;
use LIVCK\Cloud\Enums\StatuspageAppearance;
use LIVCK\Cloud\Enums\SubscriberChannel;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Translatable;
use SensitiveParameter;

/**
 * A partial update for `PATCH /v1/statuspages/{id}`: only what was set is sent, everything
 * else stays as it is.
 *
 *     UpdateStatuspage::make()
 *         ->withPrimaryColor('#0f172a')
 *         ->withAccessType(AccessType::Password)
 *         ->withPassword($password)
 *
 * Publishing, unpublishing and the image files have their own endpoints. Every field set
 * here reads back from the returned {@see \LIVCK\Cloud\Data\Statuspage}, except the password
 * (write-only; `hasPassword` tells whether one is stored).
 */
final readonly class UpdateStatuspage
{
    /**
     * @param array<string, mixed> $changes
     */
    private function __construct(
        private array $changes = [],
    ) {}

    public static function make(): self
    {
        return new self();
    }

    /**
     * A plain string replaces every stored translation with one text in the organization's
     * default language; pass a {@see Translatable} with all languages to keep them.
     */
    public function withName(Translatable|string $name): self
    {
        return $this->with('name', Translatable::from($name)->jsonSerialize());
    }

    /**
     * A new subdomain on statuspage.de (same rules as on create). The old one stops serving at
     * once; other organizations cannot take it for a while, yours can take it back any time.
     */
    public function withSlug(string $slug): self
    {
        if (trim($slug) === '') {
            throw new InvalidArgumentException('A slug must not be blank.');
        }

        return $this->with('slug', $slug);
    }

    /** `#RRGGBB`; null (or an empty string) removes it. */
    public function withPrimaryColor(?string $color): self
    {
        return $this->with('primary_color', $color);
    }

    public function withoutPrimaryColor(): self
    {
        return $this->withPrimaryColor(null);
    }

    /** `#RRGGBB`; null (or an empty string) removes it. */
    public function withSecondaryColor(?string $color): self
    {
        return $this->with('secondary_color', $color);
    }

    public function withoutSecondaryColor(): self
    {
        return $this->withSecondaryColor(null);
    }

    /**
     * Up to 10,000 characters, sanitized by the server: read the stored value back from the
     * response. Null (or an empty string) removes it.
     */
    public function withCustomCss(?string $css): self
    {
        return $this->with('custom_css', $css);
    }

    public function withoutCustomCss(): self
    {
        return $this->withCustomCss(null);
    }

    /** A link to the legal notice (http, https or mailto); null (or an empty string) removes it. */
    public function withImprintUrl(?string $url): self
    {
        return $this->with('imprint_url', $url);
    }

    public function withoutImprintUrl(): self
    {
        return $this->withImprintUrl(null);
    }

    /** A link to the privacy policy (http, https or mailto); null (or an empty string) removes it. */
    public function withPrivacyPolicyUrl(?string $url): self
    {
        return $this->with('privacy_policy_url', $url);
    }

    public function withoutPrivacyPolicyUrl(): self
    {
        return $this->withPrivacyPolicyUrl(null);
    }

    public function withShowLogo(bool $show): self
    {
        return $this->with('show_logo', $show);
    }

    public function withLogoSize(LogoSize $size): self
    {
        return $this->with('logo_size', $size);
    }

    /** The LIVI character in the header instead of the plain status symbol. */
    public function withShowLivi(bool $show): self
    {
        return $this->with('show_livi', $show);
    }

    /**
     * The mode a visitor gets until they pick one on the page; {@see StatuspageAppearance::System}
     * follows the light or dark setting of their device.
     */
    public function withAppearance(StatuspageAppearance $appearance): self
    {
        return $this->with('appearance', $appearance);
    }

    /**
     * Whether the page lets visitors pick light, dark or their device's setting. Off, every
     * visitor gets the page's appearance, whatever they picked before.
     */
    public function withAllowAppearanceSwitch(bool $allow): self
    {
        return $this->with('allow_appearance_switch', $allow);
    }

    /** Whether incidents and maintenances list the services they affect. */
    public function withShowAffectedServices(bool $show): self
    {
        return $this->with('show_affected_services', $show);
    }

    /**
     * Whether that list also names services that have no component on this page, by their
     * monitor names. Off by default: it exposes internal names.
     */
    public function withShowUnlinkedServices(bool $show): self
    {
        return $this->with('show_unlinked_services', $show);
    }

    public function withShowIncidentHistory(bool $show): self
    {
        return $this->with('show_incident_history', $show);
    }

    /**
     * Whether the page's status JSON also lists the operational children that groups with
     * `hideOperationalChildren` leave off the page. Off by default.
     */
    public function withStatusJsonIncludesHidden(bool $include): self
    {
        return $this->with('status_json_includes_hidden', $include);
    }

    /**
     * {@see AccessType::Password} needs a password, sent in the same update
     * ({@see withPassword()}) or stored before. {@see AccessType::EmailWhitelist} needs a
     * non-empty whitelist. Switching to any other type deletes the stored password.
     */
    public function withAccessType(AccessType $type): self
    {
        return $this->with('access_type', $type);
    }

    /**
     * The password visitors enter under {@see AccessType::Password}: 8 to 255 characters,
     * stored hashed, never returned.
     */
    public function withPassword(#[SensitiveParameter] string $password): self
    {
        if (trim($password) === '') {
            throw new InvalidArgumentException('A password must not be blank; the server would ignore it.');
        }

        return $this->with('password', $password);
    }

    /**
     * The addresses allowed in under {@see AccessType::EmailWhitelist}. The list replaces the
     * stored one; an empty list clears it, which the server refuses while that access type is
     * active.
     *
     * @param list<string> $emails
     */
    public function withEmailWhitelist(array $emails): self
    {
        return $this->with('email_whitelist', array_values($emails));
    }

    /** The channels visitors can subscribe through; at least one. Replaces the stored list. */
    public function withSubscriberChannels(SubscriberChannel ...$channels): self
    {
        return $this->with('subscriber_channels', array_values($channels));
    }

    /**
     * The languages the page offers: a subset of the organization's languages. Null makes the
     * page use the organization's languages again.
     *
     * @param list<string>|null $locales
     */
    public function withSupportedLocales(?array $locales): self
    {
        return $this->with('supported_locales', $locales === null ? null : array_values($locales));
    }

    /**
     * The language a visitor without a usable preference gets; must be among the languages
     * the page ends up offering. Null makes the page use the organization's default again.
     */
    public function withDefaultLocale(?string $locale): self
    {
        return $this->with('default_locale', $locale);
    }

    /** Drop the page's own languages: it serves the organization's again. */
    public function withOrganizationLocales(): self
    {
        return $this->withSupportedLocales(null)->withDefaultLocale(null);
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->changes;
    }

    private function with(string $field, mixed $value): self
    {
        return new self([...$this->changes, $field => $value]);
    }
}
