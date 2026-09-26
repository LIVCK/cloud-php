<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Fixtures;

/**
 * Status pages, components and custom domains in the shape the API sends them: the key set
 * of `StatuspageResource`, `StatuspageComponentResource` and `CustomDomainResource`, with
 * values as the server writes them.
 */
final class StatuspageFixtures
{
    public const string PAGE_ID = 'Qm7pXv2LkT9sWb4NcR8aZ';

    public const string GROUP_ID = 'Gr0uPa1b2C3d4E5f6G7h8';

    public const string SYNCED_GROUP_ID = 'SyNcG1r2O3u4P5x6Y7z8A';

    public const string COMPONENT_ID = 'Cm9pQ8rS7tU6vW5xY4zA1';

    public const string SERVICE_ID = 'Sv9aB3cD5eF7gH1iJ2kLm';

    public const string TAG_ID = 'V1StGXR8Z5jdHi6BmyT01';

    public const string DOMAIN_ID = 'Dm4xK8pL2nQ6rS0tU3vWa';

    public const string TXT_TOKEN = 'k3Jd9QzR2vLm8XwP4sNt6YbH1cFg7AeU5oIi0rKq';

    public const string COMPONENTS_PATH = '/v1/statuspages/' . self::PAGE_ID . '/components';

    public const string DOMAINS_PATH = '/v1/statuspages/' . self::PAGE_ID . '/custom-domains';

    /**
     * A page as `get`, `create`, `update`, `publish` and `unpublish` return it: with its
     * components.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function page(array $overrides = []): array
    {
        return [
            'id' => self::PAGE_ID,
            'name' => 'Acme Hosting',
            'name_translations' => ['de' => 'Acme Hosting', 'en' => 'Acme Hosting'],
            'slug' => 'acme-hosting',
            'url' => 'https://acme-hosting.statuspage.de',
            'is_published' => true,
            'theme' => 'default',
            'supported_locales' => null,
            'default_locale' => null,
            'effective_supported_locales' => ['de', 'en'],
            'effective_default_locale' => 'de',
            'primary_color' => null,
            'secondary_color' => null,
            'custom_css' => null,
            'imprint_url' => null,
            'privacy_policy_url' => null,
            'show_logo' => true,
            'logo_size' => 'medium',
            'show_livi' => true,
            'show_affected_services' => true,
            'show_unlinked_services' => false,
            'show_incident_history' => true,
            'status_json_includes_hidden' => false,
            'logo_url' => null,
            'logo_dark_url' => null,
            'favicon_url' => null,
            'access_type' => 'public',
            'has_password' => false,
            'email_whitelist' => [],
            'subscriber_channels' => ['email'],
            'components_count' => 2,
            'created_at' => '2026-09-05T05:42:06+00:00',
            'components' => [self::group(), self::component()],
            ...$overrides,
        ];
    }

    /**
     * A page as the list returns it: `components_count`, no `components`.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function listedPage(array $overrides = []): array
    {
        $page = self::page($overrides);
        unset($page['components']);

        return $page;
    }

    /**
     * A page as the asset endpoints return it: neither `components` nor `components_count`.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function assetResponsePage(array $overrides = []): array
    {
        $page = self::page($overrides);
        unset($page['components'], $page['components_count']);

        return $page;
    }

    /**
     * A page with every optional field set, password protected, with its own languages.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function customizedPage(array $overrides = []): array
    {
        return self::page([
            'name' => 'Acme Status',
            'name_translations' => ['de' => 'Acme Status', 'en' => 'Acme Status EN'],
            'url' => 'https://status.example.com',
            'is_published' => false,
            'supported_locales' => ['de', 'en'],
            'default_locale' => 'en',
            'effective_supported_locales' => ['de', 'en'],
            'effective_default_locale' => 'en',
            'primary_color' => '#0F172A',
            'secondary_color' => '#22C55E',
            'custom_css' => '.logo { height: 40px; }',
            'imprint_url' => 'https://example.com/imprint',
            'privacy_policy_url' => 'mailto:privacy@example.com',
            'show_logo' => false,
            'logo_size' => 'large',
            'show_livi' => false,
            'show_affected_services' => false,
            'show_unlinked_services' => true,
            'show_incident_history' => false,
            'status_json_includes_hidden' => true,
            'logo_url' => 'https://cdn.example.com/media/1/conversions/logo-optimized.png',
            'logo_dark_url' => 'https://cdn.example.com/media/2/logo-dark.svg',
            'favicon_url' => 'https://cdn.example.com/media/3/conversions/favicon-favicon-32.png',
            'access_type' => 'password',
            'has_password' => true,
            'email_whitelist' => ['ops@example.com'],
            'subscriber_channels' => ['email', 'webhook', 'slack', 'teams', 'discord', 'telegram'],
            'components_count' => 0,
            'components' => [],
            ...$overrides,
        ]);
    }

    /**
     * A plain group on the top level.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function group(array $overrides = []): array
    {
        return [
            'id' => self::GROUP_ID,
            'name' => 'Infrastructure',
            'name_translations' => ['de' => 'Infrastruktur', 'en' => 'Infrastructure'],
            'description' => null,
            'description_translations' => null,
            'status' => 'operational',
            'effective_status' => 'degraded',
            'is_group' => true,
            'is_visible' => true,
            'display_order' => 0,
            'show_uptime_bars' => false,
            'hide_operational_children' => false,
            'default_open' => true,
            'parent_id' => null,
            'sync_tag_id' => null,
            'sync_new_visible' => true,
            'is_sync_managed' => false,
            'service' => null,
            ...$overrides,
        ];
    }

    /**
     * A component linked to a service, inside {@see group()}. Its stored status says
     * operational while the page shows it degraded.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function component(array $overrides = []): array
    {
        return [
            'id' => self::COMPONENT_ID,
            'name' => 'Website',
            'name_translations' => ['en' => 'Website'],
            'description' => 'Shop and customer portal',
            'description_translations' => ['en' => 'Shop and customer portal'],
            'status' => 'operational',
            'effective_status' => 'degraded',
            'is_group' => false,
            'is_visible' => true,
            'display_order' => 3,
            'show_uptime_bars' => true,
            'hide_operational_children' => false,
            'default_open' => true,
            'parent_id' => self::GROUP_ID,
            'sync_tag_id' => null,
            'sync_new_visible' => true,
            'is_sync_managed' => false,
            'service' => ['id' => self::SERVICE_ID, 'name' => 'acme-web'],
            ...$overrides,
        ];
    }

    /**
     * A group synced to the customer tag.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function syncedGroup(array $overrides = []): array
    {
        return self::group([
            'id' => self::SYNCED_GROUP_ID,
            'name' => 'Your services',
            'name_translations' => ['en' => 'Your services'],
            'effective_status' => 'operational',
            'display_order' => 1,
            'hide_operational_children' => true,
            'default_open' => false,
            'sync_tag_id' => self::TAG_ID,
            'sync_new_visible' => false,
            ...$overrides,
        ]);
    }

    /**
     * A child the synced group added for a tagged service.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function syncManagedChild(array $overrides = []): array
    {
        return self::component([
            'id' => 'MnGd1C2h3I4l5D6x7Y8z9',
            'name' => 'acme-mail',
            'name_translations' => ['de' => 'acme-mail'],
            'description' => null,
            'description_translations' => null,
            'effective_status' => null,
            'is_visible' => false,
            'display_order' => 0,
            'show_uptime_bars' => false,
            'parent_id' => self::SYNCED_GROUP_ID,
            'is_sync_managed' => true,
            'service' => ['id' => 'Sv1mAiL2b3C4d5E6f7G8h', 'name' => 'acme-mail'],
            ...$overrides,
        ]);
    }

    /**
     * A custom domain right after attaching: pending, not checked yet.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function customDomain(array $overrides = []): array
    {
        return [
            'id' => self::DOMAIN_ID,
            'hostname' => 'status.example.com',
            'status' => 'pending_verification',
            'cname_target' => 'edge.livck-status.com',
            'txt_record_name' => '_livck-verify.status.example.com',
            'txt_record_value' => self::TXT_TOKEN,
            'verified' => false,
            'verified_at' => null,
            'last_error_code' => null,
            'statuspage_id' => self::PAGE_ID,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function activeDomain(array $overrides = []): array
    {
        return self::customDomain([
            'status' => 'active',
            'verified' => true,
            'verified_at' => '2026-09-26T05:10:28+00:00',
            ...$overrides,
        ]);
    }
}
