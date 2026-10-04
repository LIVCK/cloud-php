<?php

declare(strict_types=1);

use LIVCK\Cloud\Enums\AccessType;
use LIVCK\Cloud\Enums\LogoSize;
use LIVCK\Cloud\Enums\StatuspageAppearance;
use LIVCK\Cloud\Enums\SubscriberChannel;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Payloads\UpdateStatuspage;
use LIVCK\Cloud\Support\Json;
use LIVCK\Cloud\Support\Translatable;

it('starts empty', function (): void {
    expect(UpdateStatuspage::make()->isEmpty())->toBeTrue()
        ->and(UpdateStatuspage::make()->toArray())->toBe([]);
});

it('maps every setter to its API field', function (UpdateStatuspage $update, array $expected): void {
    expect($update->isEmpty())->toBeFalse()
        ->and($update->toArray())->toBe($expected);
})->with([
    'name' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withName('Acme'), ['name' => 'Acme']],
    'name in languages' => [
        fn(): UpdateStatuspage => UpdateStatuspage::make()->withName(Translatable::translations(['de' => 'Acme', 'en' => 'Acme EN'])),
        ['name' => ['de' => 'Acme', 'en' => 'Acme EN']],
    ],
    'slug' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withSlug('acme'), ['slug' => 'acme']],
    'primary color' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withPrimaryColor('#0F172A'), ['primary_color' => '#0F172A']],
    'secondary color' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withSecondaryColor('#22C55E'), ['secondary_color' => '#22C55E']],
    'custom css' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withCustomCss('.x{}'), ['custom_css' => '.x{}']],
    'imprint' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withImprintUrl('https://example.com/imprint'), ['imprint_url' => 'https://example.com/imprint']],
    'privacy' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withPrivacyPolicyUrl('https://example.com/privacy'), ['privacy_policy_url' => 'https://example.com/privacy']],
    'show logo' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withShowLogo(false), ['show_logo' => false]],
    'logo size' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withLogoSize(LogoSize::Small), ['logo_size' => LogoSize::Small]],
    'show livi' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withShowLivi(false), ['show_livi' => false]],
    'appearance' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withAppearance(StatuspageAppearance::Dark), ['appearance' => StatuspageAppearance::Dark]],
    'appearance switch' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withAllowAppearanceSwitch(false), ['allow_appearance_switch' => false]],
    'affected services' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withShowAffectedServices(false), ['show_affected_services' => false]],
    'unlinked services' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withShowUnlinkedServices(true), ['show_unlinked_services' => true]],
    'incident history' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withShowIncidentHistory(false), ['show_incident_history' => false]],
    'status json' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withStatusJsonIncludesHidden(true), ['status_json_includes_hidden' => true]],
    'access type' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withAccessType(AccessType::EmailWhitelist), ['access_type' => AccessType::EmailWhitelist]],
    'password' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withPassword('super-secret-1'), ['password' => 'super-secret-1']],
    'email whitelist' => [
        fn(): UpdateStatuspage => UpdateStatuspage::make()->withEmailWhitelist(['ops@example.com', 'noc@example.com']),
        ['email_whitelist' => ['ops@example.com', 'noc@example.com']],
    ],
    'empty email whitelist' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withEmailWhitelist([]), ['email_whitelist' => []]],
    'subscriber channels' => [
        fn(): UpdateStatuspage => UpdateStatuspage::make()->withSubscriberChannels(SubscriberChannel::Email, SubscriberChannel::Telegram),
        ['subscriber_channels' => [SubscriberChannel::Email, SubscriberChannel::Telegram]],
    ],
    'supported locales' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withSupportedLocales(['en', 'de']), ['supported_locales' => ['en', 'de']]],
    'default locale' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withDefaultLocale('en'), ['default_locale' => 'en']],
]);

it('sends explicit nulls to remove optional values', function (UpdateStatuspage $update, array $expected): void {
    expect($update->toArray())->toBe($expected);
})->with([
    'primary color' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withoutPrimaryColor(), ['primary_color' => null]],
    'secondary color' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withoutSecondaryColor(), ['secondary_color' => null]],
    'custom css' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withoutCustomCss(), ['custom_css' => null]],
    'imprint' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withoutImprintUrl(), ['imprint_url' => null]],
    'privacy' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withoutPrivacyPolicyUrl(), ['privacy_policy_url' => null]],
    'null through the setter' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withPrimaryColor(null), ['primary_color' => null]],
    'own languages' => [
        fn(): UpdateStatuspage => UpdateStatuspage::make()->withOrganizationLocales(),
        ['supported_locales' => null, 'default_locale' => null],
    ],
    'supported locales only' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withSupportedLocales(null), ['supported_locales' => null]],
]);

it('encodes enums by value on the wire', function (): void {
    $update = UpdateStatuspage::make()
        ->withAccessType(AccessType::Password)
        ->withLogoSize(LogoSize::Large)
        ->withAppearance(StatuspageAppearance::Light)
        ->withSubscriberChannels(SubscriberChannel::Email, SubscriberChannel::Discord);

    expect(Json::encode($update->toArray()))->toBe('{"access_type":"password","logo_size":"large","appearance":"light","subscriber_channels":["email","discord"]}');
});

it('keeps the last value of a field and is immutable', function (): void {
    $base = UpdateStatuspage::make()->withSlug('first');
    $changed = $base->withSlug('second')->withShowLogo(true);

    expect($base->toArray())->toBe(['slug' => 'first'])
        ->and($changed->toArray())->toBe(['slug' => 'second', 'show_logo' => true]);
});

it('refuses blank values the server would ignore or reject', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank name' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withName(''), 'must not be blank'],
    'blank slug' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withSlug(' '), 'slug must not be blank'],
    'blank password' => [fn(): UpdateStatuspage => UpdateStatuspage::make()->withPassword('  '), 'password must not be blank'],
]);

it('keeps the password out of stack traces', function (): void {
    $parameter = new ReflectionParameter([UpdateStatuspage::class, 'withPassword'], 'password');

    expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});
