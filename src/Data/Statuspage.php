<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use DateTimeImmutable;
use LIVCK\Cloud\Data\Concerns\ReadsStringMaps;
use LIVCK\Cloud\Enums\AccessType;
use LIVCK\Cloud\Enums\LogoSize;
use LIVCK\Cloud\Enums\StatuspageAppearance;
use LIVCK\Cloud\Enums\SubscriberChannel;
use LIVCK\Cloud\Support\Field;

/**
 * A public status page, served at `https://{slug}.statuspage.de` and under its verified
 * custom domains.
 *
 * `name` is resolved to one language, the one the client asked for (`ClientOptions::$locale`)
 * or the organization's default; `nameTranslations` holds every stored language.
 */
final readonly class Statuspage
{
    use ReadsStringMaps;

    /**
     * @param array<string, string>|null $nameTranslations the name per locale
     * @param string $slug the subdomain on statuspage.de; unique across all organizations
     * @param string|null $url the address to hand out: the custom domain attached first once it is verified,
     *                         otherwise the statuspage.de subdomain. An unpublished page has one too; it answers
     *                         with 404 until the page is published.
     * @param bool $isPublished whether the page is online. Unpublished pages count as drafts.
     * @param string $theme the page's theme; not writable through the API
     * @param list<string>|null $supportedLocales the page's own languages; null when it uses the organization's
     * @param string|null $defaultLocale the page's own default language; null when it uses the organization's
     * @param list<string> $effectiveSupportedLocales the languages the page serves
     * @param string $effectiveDefaultLocale the language a visitor without a usable preference gets
     * @param string|null $customCss as stored: the server sanitizes what it receives
     * @param bool $showLivi whether the header shows the LIVI character instead of the plain status symbol
     * @param bool $showAffectedServices whether incidents and maintenances list the services they affect
     * @param bool $showUnlinkedServices whether that list also names services without a component on this page,
     *                                   by their monitor names
     * @param bool $showIncidentHistory whether the page shows past incidents
     * @param bool $statusJsonIncludesHidden whether the page's status JSON also lists the operational children that
     *                                       groups with `hideOperationalChildren` leave off the page
     * @param string|null $logoUrl served address of the uploaded logo
     * @param string|null $logoDarkUrl served address of the uploaded logo for the dark theme
     * @param string|null $faviconUrl served address of the uploaded favicon
     * @param bool $hasPassword whether a password is stored; the password itself is never returned
     * @param list<string> $emailWhitelist the addresses allowed in with {@see AccessType::EmailWhitelist}
     * @param list<SubscriberChannel> $subscriberChannels channels visitors can subscribe through
     * @param int|null $componentsCount number of components; absent from the responses of the asset endpoints
     * @param list<StatuspageComponent>|null $components the component tree as a flat list in display order.
     *                                                  Sent by `get`, `create`, `update`, `publish` and
     *                                                  `unpublish`; null in lists and asset responses.
     * @param array<string, mixed> $raw the payload as received, for fields the SDK does not map yet
     * @param StatuspageAppearance $appearance the mode a visitor gets until they pick one on the page;
     *                                         {@see StatuspageAppearance::System} follows the light or dark
     *                                         setting of their device
     * @param bool $allowAppearanceSwitch whether the page lets visitors pick light, dark or their device's setting.
     *                                    False gives every visitor `appearance`. Both follow `$raw` so that
     *                                    constructor calls written for earlier versions keep working.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?array $nameTranslations,
        public string $slug,
        public ?string $url,
        public bool $isPublished,
        public string $theme,
        public ?array $supportedLocales,
        public ?string $defaultLocale,
        public array $effectiveSupportedLocales,
        public string $effectiveDefaultLocale,
        public ?string $primaryColor,
        public ?string $secondaryColor,
        public ?string $customCss,
        public ?string $imprintUrl,
        public ?string $privacyPolicyUrl,
        public bool $showLogo,
        public LogoSize $logoSize,
        public bool $showLivi,
        public bool $showAffectedServices,
        public bool $showUnlinkedServices,
        public bool $showIncidentHistory,
        public bool $statusJsonIncludesHidden,
        public ?string $logoUrl,
        public ?string $logoDarkUrl,
        public ?string $faviconUrl,
        public AccessType $accessType,
        public bool $hasPassword,
        public array $emailWhitelist,
        public array $subscriberChannels,
        public ?int $componentsCount,
        public ?DateTimeImmutable $createdAt,
        public ?array $components,
        public array $raw,
        public StatuspageAppearance $appearance = StatuspageAppearance::System,
        public bool $allowAppearanceSwitch = true,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $supportedLocales = $data['supported_locales'] ?? null;
        $components = ($data['components'] ?? null) === null
            ? null
            : array_map(StatuspageComponent::fromArray(...), Field::objectList($data, 'components'));

        return new self(
            Field::string($data, 'id'),
            Field::string($data, 'name'),
            self::nullableStringMap($data, 'name_translations'),
            Field::string($data, 'slug'),
            Field::nullableString($data, 'url'),
            Field::bool($data, 'is_published'),
            Field::string($data, 'theme'),
            $supportedLocales === null ? null : Field::stringList($data, 'supported_locales'),
            Field::nullableString($data, 'default_locale'),
            Field::stringList($data, 'effective_supported_locales'),
            Field::string($data, 'effective_default_locale'),
            Field::nullableString($data, 'primary_color'),
            Field::nullableString($data, 'secondary_color'),
            Field::nullableString($data, 'custom_css'),
            Field::nullableString($data, 'imprint_url'),
            Field::nullableString($data, 'privacy_policy_url'),
            Field::bool($data, 'show_logo'),
            LogoSize::fromApi(Field::string($data, 'logo_size')),
            Field::bool($data, 'show_livi'),
            Field::bool($data, 'show_affected_services'),
            Field::bool($data, 'show_unlinked_services'),
            Field::bool($data, 'show_incident_history'),
            Field::bool($data, 'status_json_includes_hidden'),
            Field::nullableString($data, 'logo_url'),
            Field::nullableString($data, 'logo_dark_url'),
            Field::nullableString($data, 'favicon_url'),
            AccessType::fromApi(Field::string($data, 'access_type')),
            Field::bool($data, 'has_password'),
            Field::stringList($data, 'email_whitelist'),
            array_map(SubscriberChannel::fromApi(...), Field::stringList($data, 'subscriber_channels')),
            Field::nullableInt($data, 'components_count'),
            Field::nullableInstant($data, 'created_at'),
            $components,
            $data,
            StatuspageAppearance::fromApi(Field::string($data, 'appearance')),
            Field::bool($data, 'allow_appearance_switch'),
        );
    }

    /** Whether the page uses its own languages rather than the organization's. */
    public function hasOwnLocales(): bool
    {
        return $this->supportedLocales !== null || $this->defaultLocale !== null;
    }
}
