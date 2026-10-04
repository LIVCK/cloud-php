<?php

declare(strict_types=1);

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\Data\Reference;
use LIVCK\Cloud\Data\Statuspage;
use LIVCK\Cloud\Data\StatuspageComponent;
use LIVCK\Cloud\Enums\AccessType;
use LIVCK\Cloud\Enums\AssetType;
use LIVCK\Cloud\Enums\ComponentStatus;
use LIVCK\Cloud\Enums\LogoSize;
use LIVCK\Cloud\Enums\StatuspageAppearance;
use LIVCK\Cloud\Enums\SubscriberChannel;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Payloads\AssetFile;
use LIVCK\Cloud\Payloads\CreateStatuspage;
use LIVCK\Cloud\Payloads\UpdateStatuspage;
use LIVCK\Cloud\Query\StatuspageQuery;
use LIVCK\Cloud\Resources\CustomDomainsInterface;
use LIVCK\Cloud\Resources\StatuspageComponentsInterface;
use LIVCK\Cloud\Resources\StatuspagesInterface;
use LIVCK\Cloud\Support\Translatable;
use LIVCK\Cloud\Support\Uuid;
use LIVCK\Cloud\Testing\MockResponse;
use LIVCK\Cloud\Testing\RecordedRequest;
use LIVCK\Cloud\Tests\Fixtures\StatuspageFixtures;

describe('client', function (): void {
    it('exposes the resource as one memoised instance', function (): void {
        [$client] = fakeClient();

        expect($client->statuspages())->toBeInstanceOf(StatuspagesInterface::class)
            ->and($client->statuspages())->toBe($client->statuspages());
    });
});

describe('list', function (): void {
    it('lists pages without their components', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([StatuspageFixtures::listedPage()])]);

        $page = $client->statuspages()->list();
        $first = $page->first();

        expect($page)->toBeInstanceOf(Page::class)
            ->and($page->total)->toBe(1)
            ->and($first)->toBeInstanceOf(Statuspage::class)
            ->and($first?->id)->toBe(StatuspageFixtures::PAGE_ID)
            ->and($first?->componentsCount)->toBe(2)
            ->and($first?->components)->toBeNull()
            ->and($first?->raw)->toBe(StatuspageFixtures::listedPage());

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/statuspages') && $r->queryString() === '');
    });

    it('sends the slug filter and paging of a query', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([])]);

        $client->statuspages()->list(StatuspageQuery::make()->withSlug('acme-4711')->withPage(3)->withPerPage(25));

        expect($http->lastRequest()?->query())->toBe(['slug' => 'acme-4711', 'page' => '3', 'per_page' => '25']);
    });

    it('follows the pages with the same page size', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::page([StatuspageFixtures::listedPage(['id' => 'p1'])], currentPage: 1, lastPage: 2, perPage: 1),
            MockResponse::page([StatuspageFixtures::listedPage(['id' => 'p2'])], currentPage: 2, lastPage: 2, perPage: 1),
        ]);

        $second = $client->statuspages()->list(StatuspageQuery::make()->withPerPage(1))->nextPage();

        expect($second?->items[0]->id)->toBe('p2')
            ->and($http->recorded()[1]->query())->toBe(['page' => '2', 'per_page' => '1']);
    });
});

describe('each', function (): void {
    it('yields every page across the list, one request per list page', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::page([StatuspageFixtures::listedPage(['id' => 'a']), StatuspageFixtures::listedPage(['id' => 'b'])], currentPage: 1, lastPage: 2, perPage: 2),
            MockResponse::page([StatuspageFixtures::listedPage(['id' => 'c'])], currentPage: 2, lastPage: 2, perPage: 2),
        ]);

        $ids = [];

        foreach ($client->statuspages()->each() as $statuspage) {
            $ids[] = $statuspage->id;
        }

        expect($ids)->toBe(['a', 'b', 'c'])
            ->and($http->recorded())->toHaveCount(2);
    });
});

describe('get', function (): void {
    it('hydrates a page with the defaults of a new one and its components', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page()])]);

        $page = $client->statuspages()->get(StatuspageFixtures::PAGE_ID);

        expect($page->id)->toBe(StatuspageFixtures::PAGE_ID)
            ->and($page->name)->toBe('Acme Hosting')
            ->and($page->nameTranslations)->toBe(['de' => 'Acme Hosting', 'en' => 'Acme Hosting'])
            ->and($page->slug)->toBe('acme-hosting')
            ->and($page->url)->toBe('https://acme-hosting.statuspage.de')
            ->and($page->isPublished)->toBeTrue()
            ->and($page->theme)->toBe('default')
            ->and($page->supportedLocales)->toBeNull()
            ->and($page->defaultLocale)->toBeNull()
            ->and($page->hasOwnLocales())->toBeFalse()
            ->and($page->effectiveSupportedLocales)->toBe(['de', 'en'])
            ->and($page->effectiveDefaultLocale)->toBe('de')
            ->and($page->primaryColor)->toBeNull()
            ->and($page->secondaryColor)->toBeNull()
            ->and($page->customCss)->toBeNull()
            ->and($page->imprintUrl)->toBeNull()
            ->and($page->privacyPolicyUrl)->toBeNull()
            ->and($page->showLogo)->toBeTrue()
            ->and($page->logoSize)->toBe(LogoSize::Medium)
            ->and($page->showLivi)->toBeTrue()
            ->and($page->appearance)->toBe(StatuspageAppearance::System)
            ->and($page->allowAppearanceSwitch)->toBeTrue()
            ->and($page->showAffectedServices)->toBeTrue()
            ->and($page->showUnlinkedServices)->toBeFalse()
            ->and($page->showIncidentHistory)->toBeTrue()
            ->and($page->statusJsonIncludesHidden)->toBeFalse()
            ->and($page->logoUrl)->toBeNull()
            ->and($page->logoDarkUrl)->toBeNull()
            ->and($page->faviconUrl)->toBeNull()
            ->and($page->accessType)->toBe(AccessType::Public)
            ->and($page->hasPassword)->toBeFalse()
            ->and($page->emailWhitelist)->toBe([])
            ->and($page->subscriberChannels)->toBe([SubscriberChannel::Email])
            ->and($page->componentsCount)->toBe(2)
            ->and($page->createdAt?->format(DATE_ATOM))->toBe('2026-09-05T05:42:06+00:00')
            ->and($page->raw)->toBe(StatuspageFixtures::page());

        $components = $page->components ?? [];

        expect($page->components)->not->toBeNull()
            ->and($components)->toHaveCount(2)
            ->and($components[0])->toBeInstanceOf(StatuspageComponent::class)
            ->and($components[0]->id)->toBe(StatuspageFixtures::GROUP_ID)
            ->and($components[1]->parentId)->toBe(StatuspageFixtures::GROUP_ID)
            ->and($components[1]->service)->toBeInstanceOf(Reference::class)
            ->and($components[1]->effectiveStatus)->toBe(ComponentStatus::Degraded);

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('GET', '/v1/statuspages/' . StatuspageFixtures::PAGE_ID));
    });

    it('hydrates every optional field of a customized page', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::customizedPage()])]);

        $page = $client->statuspages()->get(StatuspageFixtures::PAGE_ID);

        expect($page->nameTranslations)->toBe(['de' => 'Acme Status', 'en' => 'Acme Status EN'])
            ->and($page->url)->toBe('https://status.example.com')
            ->and($page->isPublished)->toBeFalse()
            ->and($page->supportedLocales)->toBe(['de', 'en'])
            ->and($page->defaultLocale)->toBe('en')
            ->and($page->hasOwnLocales())->toBeTrue()
            ->and($page->effectiveDefaultLocale)->toBe('en')
            ->and($page->primaryColor)->toBe('#0F172A')
            ->and($page->secondaryColor)->toBe('#22C55E')
            ->and($page->customCss)->toBe('.logo { height: 40px; }')
            ->and($page->imprintUrl)->toBe('https://example.com/imprint')
            ->and($page->privacyPolicyUrl)->toBe('mailto:privacy@example.com')
            ->and($page->showLogo)->toBeFalse()
            ->and($page->logoSize)->toBe(LogoSize::Large)
            ->and($page->showLivi)->toBeFalse()
            ->and($page->appearance)->toBe(StatuspageAppearance::Dark)
            ->and($page->allowAppearanceSwitch)->toBeFalse()
            ->and($page->showAffectedServices)->toBeFalse()
            ->and($page->showUnlinkedServices)->toBeTrue()
            ->and($page->showIncidentHistory)->toBeFalse()
            ->and($page->statusJsonIncludesHidden)->toBeTrue()
            ->and($page->logoUrl)->toBe('https://cdn.example.com/media/1/conversions/logo-optimized.png')
            ->and($page->logoDarkUrl)->toBe('https://cdn.example.com/media/2/logo-dark.svg')
            ->and($page->faviconUrl)->toBe('https://cdn.example.com/media/3/conversions/favicon-favicon-32.png')
            ->and($page->accessType)->toBe(AccessType::Password)
            ->and($page->hasPassword)->toBeTrue()
            ->and($page->emailWhitelist)->toBe(['ops@example.com'])
            ->and($page->subscriberChannels)->toBe([
                SubscriberChannel::Email,
                SubscriberChannel::Webhook,
                SubscriberChannel::Slack,
                SubscriberChannel::Teams,
                SubscriberChannel::Discord,
                SubscriberChannel::Telegram,
            ])
            ->and($page->componentsCount)->toBe(0)
            ->and($page->components)->toBe([]);
    });

    it('reads the other access types and logo sizes', function (string $wire, AccessType $accessType, string $size, LogoSize $logoSize): void {
        [$client] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page(['access_type' => $wire, 'logo_size' => $size])])]);

        $page = $client->statuspages()->get('p');

        expect($page->accessType)->toBe($accessType)
            ->and($page->logoSize)->toBe($logoSize);
    })->with([
        'email whitelist, small' => ['email_whitelist', AccessType::EmailWhitelist, 'small', LogoSize::Small],
        'public, medium' => ['public', AccessType::Public, 'medium', LogoSize::Medium],
    ]);

    it('reads every appearance', function (string $wire, StatuspageAppearance $appearance): void {
        [$client] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page(['appearance' => $wire])])]);

        expect($client->statuspages()->get('p')->appearance)->toBe($appearance);
    })->with([
        'system' => ['system', StatuspageAppearance::System],
        'light' => ['light', StatuspageAppearance::Light],
        'dark' => ['dark', StatuspageAppearance::Dark],
    ]);

    it('keeps unknown enum values readable', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page([
            'access_type' => 'sso',
            'logo_size' => 'huge',
            'appearance' => 'sepia',
            'subscriber_channels' => ['email', 'carrier_pigeon'],
        ])])]);

        $page = $client->statuspages()->get('p');

        expect($page->accessType)->toBe(AccessType::Unrecognized)
            ->and($page->logoSize)->toBe(LogoSize::Unrecognized)
            ->and($page->appearance)->toBe(StatuspageAppearance::Unrecognized)
            ->and($page->subscriberChannels)->toBe([SubscriberChannel::Email, SubscriberChannel::Unrecognized])
            ->and($page->raw['access_type'])->toBe('sso')
            ->and($page->raw['logo_size'])->toBe('huge')
            ->and($page->raw['appearance'])->toBe('sepia');
    });

    it('percent-encodes the id in the path', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page()])]);

        $client->statuspages()->get('odd/id');

        expect($http->lastRequest()?->uri)->toBe('https://api.livck.cloud/v1/statuspages/odd%2Fid');
    });

    it('raises not found', function (): void {
        [$client] = singleShotClient([MockResponse::error('The requested resource was not found.', 404)]);

        expect(fn(): Statuspage => $client->statuspages()->get('missing'))->toThrow(NotFoundException::class);
    });
});

describe('findBySlug', function (): void {
    it('asks for exactly one page by slug and returns it', function (): void {
        [$client, $http] = fakeClient([MockResponse::page([StatuspageFixtures::listedPage()])]);

        $page = $client->statuspages()->findBySlug('acme-hosting');

        expect($page?->slug)->toBe('acme-hosting')
            ->and($page?->components)->toBeNull()
            ->and($http->lastRequest()?->query())->toBe(['slug' => 'acme-hosting', 'per_page' => '1']);
    });

    it('returns null when nothing matches', function (): void {
        [$client] = fakeClient([MockResponse::page([])]);

        expect($client->statuspages()->findBySlug('acme-0000'))->toBeNull();
    });

    it('refuses a blank slug before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): ?Statuspage => $client->statuspages()->findBySlug('  '))->toThrow(InvalidArgumentException::class, 'blank');
        $http->assertNothingSent();
    });
});

describe('create', function (): void {
    it('sends the name alone and lets the server derive the slug', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page(['components' => [], 'components_count' => 0])], 201)]);

        $page = $client->statuspages()->create(CreateStatuspage::make('Acme Hosting'));

        expect($page->slug)->toBe('acme-hosting')
            ->and($page->components)->toBe([]);

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/statuspages')
            && $r->json() === ['name' => 'Acme Hosting']
            && $r->idempotencyKey() !== null);
    });

    it('sends a slug and a name in several languages', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page()], 201)]);

        $client->statuspages()->create(
            CreateStatuspage::make(Translatable::translations(['de' => 'Acme Status', 'en' => 'Acme Status EN']))->withSlug('acme-status'),
        );

        expect($http->lastRequest()?->body)->toBe('{"name":{"de":"Acme Status","en":"Acme Status EN"},"slug":"acme-status"}');
    });

    it('reports an unpublished page when no published slot is free', function (): void {
        [$client] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page(['is_published' => false])], 201)]);

        expect($client->statuspages()->create(CreateStatuspage::make('Draft'))->isPublished)->toBeFalse();
    });

    it('surfaces a taken slug and the total page cap as validation errors', function (string $field, string $message): void {
        [$client] = singleShotClient([MockResponse::error($message, 422, [$field => [$message]])]);

        try {
            $client->statuspages()->create(CreateStatuspage::make('Acme')->withSlug('taken'));
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->hasError($field))->toBeTrue();
        }
    })->with([
        'slug taken' => ['slug', 'This slug is already taken.'],
        'total cap' => ['name', 'You have reached the maximum number of status pages for your plan.'],
    ]);

    it('surfaces a token without the create ability', function (): void {
        [$client] = singleShotClient([MockResponse::error('This API token does not have the required ability.', 403)]);

        expect(fn(): Statuspage => $client->statuspages()->create(CreateStatuspage::make('Acme')))->toThrow(PermissionDeniedException::class);
    });

    it('sends the given idempotency key instead of a generated one', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page()], 201)]);

        $client->statuspages()->create(CreateStatuspage::make('Acme'), 'onboard-4711-statuspage');

        expect($http->lastRequest()?->idempotencyKey())->toBe('onboard-4711-statuspage');
    });

    it('generates a UUID v4 key when none is given', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page()], 201)]);

        $client->statuspages()->create(CreateStatuspage::make('Acme'));

        expect(Uuid::isV4((string) $http->lastRequest()?->idempotencyKey()))->toBeTrue();
    });

    it('refuses a malformed idempotency key before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): Statuspage => $client->statuspages()->create(CreateStatuspage::make('Acme'), 'has space'))
            ->toThrow(InvalidArgumentException::class, 'visible ASCII');
        $http->assertNothingSent();
    });
});

describe('update', function (): void {
    it('patches only what was set and reads it back', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::customizedPage()])]);

        $page = $client->statuspages()->update(StatuspageFixtures::PAGE_ID, UpdateStatuspage::make()
            ->withPrimaryColor('#0F172A')
            ->withAccessType(AccessType::Password)
            ->withPassword('super-secret-1')
            ->withLogoSize(LogoSize::Large)
            ->withAppearance(StatuspageAppearance::Dark)
            ->withAllowAppearanceSwitch(false)
            ->withSubscriberChannels(SubscriberChannel::Email, SubscriberChannel::Webhook));

        expect($page->primaryColor)->toBe('#0F172A')
            ->and($page->hasPassword)->toBeTrue()
            ->and($page->appearance)->toBe(StatuspageAppearance::Dark)
            ->and($page->allowAppearanceSwitch)->toBeFalse();

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('PATCH', '/v1/statuspages/' . StatuspageFixtures::PAGE_ID)
            && $r->json() === [
                'primary_color' => '#0F172A',
                'access_type' => 'password',
                'password' => 'super-secret-1',
                'logo_size' => 'large',
                'appearance' => 'dark',
                'allow_appearance_switch' => false,
                'subscriber_channels' => ['email', 'webhook'],
            ]
            && ! $r->hasHeader('Idempotency-Key'));
    });

    it('sends explicit nulls to clear fields', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page()])]);

        $client->statuspages()->update('p', UpdateStatuspage::make()->withoutCustomCss()->withOrganizationLocales());

        expect($http->lastRequest()?->body)->toBe('{"custom_css":null,"supported_locales":null,"default_locale":null}');
    });

    it('refuses an empty update before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): Statuspage => $client->statuspages()->update('p', UpdateStatuspage::make()))->toThrow(InvalidArgumentException::class, 'no changes');
        $http->assertNothingSent();
    });

    it('refuses an enum case the SDK does not know before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): Statuspage => $client->statuspages()->update('p', UpdateStatuspage::make()->withLogoSize(LogoSize::Unrecognized)))
            ->toThrow(InvalidArgumentException::class, 'Unrecognized')
            ->and(fn(): Statuspage => $client->statuspages()->update('p', UpdateStatuspage::make()->withAppearance(StatuspageAppearance::Unrecognized)))
            ->toThrow(InvalidArgumentException::class, 'Unrecognized');
        $http->assertNothingSent();
    });

    it('surfaces password access without a password', function (): void {
        [$client] = singleShotClient([MockResponse::error(
            'A password is required for password-protected access.',
            422,
            ['password' => ['A password is required for password-protected access.']],
        )]);

        try {
            $client->statuspages()->update('p', UpdateStatuspage::make()->withAccessType(AccessType::Password));
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->hasError('password'))->toBeTrue();
        }
    });
});

describe('delete', function (): void {
    it('deletes and returns nothing', function (): void {
        [$client, $http] = fakeClient([MockResponse::noContent()]);

        $client->statuspages()->delete(StatuspageFixtures::PAGE_ID);

        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('DELETE', '/v1/statuspages/' . StatuspageFixtures::PAGE_ID) && $r->body === '');

        expect($http->recorded())->toHaveCount(1)
            ->and($http->lastRequest()?->hasHeader('Idempotency-Key'))->toBeFalse();
    });

    it('raises not found for an unknown page', function (): void {
        [$client] = singleShotClient([MockResponse::error('The requested resource was not found.', 404)]);

        expect(fn() => $client->statuspages()->delete('missing'))->toThrow(NotFoundException::class);
    });
});

describe('publish and unpublish', function (): void {
    it('publishes with an empty POST', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page()])]);

        $page = $client->statuspages()->publish(StatuspageFixtures::PAGE_ID);

        expect($page->isPublished)->toBeTrue();
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/statuspages/' . StatuspageFixtures::PAGE_ID . '/publish')
            && $r->body === ''
            && $r->idempotencyKey() !== null);
    });

    it('surfaces the published-pages limit as a validation error on is_published', function (): void {
        [$client] = singleShotClient([MockResponse::error(
            'Your plan allows no further published status pages.',
            422,
            ['is_published' => ['Your plan allows no further published status pages.']],
        )]);

        try {
            $client->statuspages()->publish('p');
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->hasError('is_published'))->toBeTrue();
        }
    });

    it('unpublishes with an empty POST', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page(['is_published' => false])])]);

        $page = $client->statuspages()->unpublish(StatuspageFixtures::PAGE_ID);

        expect($page->isPublished)->toBeFalse();
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('POST', '/v1/statuspages/' . StatuspageFixtures::PAGE_ID . '/unpublish') && $r->body === '');
    });
});

describe('uploadAsset', function (): void {
    it('sends the file as the multipart field "file" and reads the served address back', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::assetResponsePage([
            'logo_dark_url' => 'https://cdn.example.com/media/2/logo-dark.svg',
        ])])]);

        $page = $client->statuspages()->uploadAsset(
            StatuspageFixtures::PAGE_ID,
            AssetType::LogoDark,
            AssetFile::fromContents('<svg xmlns="http://www.w3.org/2000/svg"/>', 'logo-dark.svg'),
        );

        expect($page->logoDarkUrl)->toBe('https://cdn.example.com/media/2/logo-dark.svg')
            ->and($page->components)->toBeNull()
            ->and($page->componentsCount)->toBeNull();

        $sent = $http->lastRequest();

        expect($sent?->matches('POST', '/v1/statuspages/' . StatuspageFixtures::PAGE_ID . '/assets/logo-dark'))->toBeTrue()
            ->and($sent?->header('Content-Type'))->toStartWith('multipart/form-data; boundary=')
            ->and($sent?->body)->toContain('Content-Disposition: form-data; name="file"; filename="logo-dark.svg"')
            ->and($sent?->body)->toContain('Content-Type: image/svg+xml')
            ->and($sent?->body)->toContain('<svg xmlns="http://www.w3.org/2000/svg"/>');
    });

    it('streams a file from disk under its own name', function (): void {
        $path = sys_get_temp_dir() . '/livck-sdk-' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\nfavicon-bytes");

        try {
            [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::assetResponsePage()])]);

            $client->statuspages()->uploadAsset('p', AssetType::Favicon, AssetFile::fromPath($path));

            expect($http->lastRequest()?->path())->toBe('/v1/statuspages/p/assets/favicon')
                ->and($http->lastRequest()?->body)->toContain('filename="' . basename($path) . '"')
                ->and($http->lastRequest()?->body)->toContain('Content-Type: image/png')
                ->and($http->lastRequest()?->body)->toContain("\x89PNG\r\n\x1a\nfavicon-bytes");
        } finally {
            unlink($path);
        }
    });

    it('sends the same file again after a network error', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::networkError(),
            MockResponse::json(['data' => StatuspageFixtures::assetResponsePage()]),
        ]);

        $client->statuspages()->uploadAsset('p', AssetType::Logo, AssetFile::fromContents('PNGDATA', 'logo.png'));

        $recorded = $http->recorded();

        expect($recorded)->toHaveCount(2)
            ->and($recorded[1]->body)->toBe($recorded[0]->body)
            ->and($recorded[1]->body)->toContain('PNGDATA')
            ->and($recorded[1]->path())->toBe('/v1/statuspages/p/assets/logo');
    });

    it('surfaces a rejected file as a validation error on file', function (): void {
        [$client] = singleShotClient([MockResponse::error('The file field must be a file of type: ico, png, svg.', 422, ['file' => ['The file field must be a file of type: ico, png, svg.']])]);

        try {
            $client->statuspages()->uploadAsset('p', AssetType::Favicon, AssetFile::fromContents('%PDF-1.7', 'favicon.pdf'));
            expect(false)->toBeTrue('a ValidationException was expected');
        } catch (ValidationException $e) {
            expect($e->hasError('file'))->toBeTrue();
        }
    });

    it('refuses an asset type the SDK does not know before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): Statuspage => $client->statuspages()->uploadAsset('p', AssetType::Unrecognized, AssetFile::fromContents('x', 'x.png')))
            ->toThrow(InvalidArgumentException::class, 'AssetType::Unrecognized');
        $http->assertNothingSent();
    });
});

describe('deleteAsset', function (): void {
    it('removes an asset and returns the page', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::assetResponsePage()])]);

        $page = $client->statuspages()->deleteAsset(StatuspageFixtures::PAGE_ID, AssetType::Logo);

        expect($page->logoUrl)->toBeNull();
        $http->assertSent(fn(RecordedRequest $r): bool => $r->matches('DELETE', '/v1/statuspages/' . StatuspageFixtures::PAGE_ID . '/assets/logo'));
    });

    it('raises not found when a repeated removal finds the page gone', function (): void {
        [$client, $http] = fakeClient([
            MockResponse::networkError(),
            MockResponse::error('The requested resource was not found.', 404),
        ]);

        expect(fn(): Statuspage => $client->statuspages()->deleteAsset('p', AssetType::Favicon))->toThrow(NotFoundException::class);
        expect($http->recorded())->toHaveCount(2);
    });

    it('refuses an asset type the SDK does not know before anything is sent', function (): void {
        [$client, $http] = fakeClient();

        expect(fn(): Statuspage => $client->statuspages()->deleteAsset('p', AssetType::Unrecognized))->toThrow(InvalidArgumentException::class);
        $http->assertNothingSent();
    });
});

describe('nested resources', function (): void {
    it('binds components and custom domains to a page without sending anything', function (): void {
        [$client, $http] = fakeClient();

        expect($client->statuspages()->components(StatuspageFixtures::PAGE_ID))->toBeInstanceOf(StatuspageComponentsInterface::class)
            ->and($client->statuspages()->customDomains(StatuspageFixtures::PAGE_ID))->toBeInstanceOf(CustomDomainsInterface::class);

        $http->assertNothingSent();
    });

    it('refuses a blank page id', function (Closure $bind): void {
        [$client] = fakeClient();

        expect(fn() => $bind($client->statuspages()))->toThrow(InvalidArgumentException::class, 'statuspage id must not be blank');
    })->with([
        'components' => [fn(StatuspagesInterface $pages): StatuspageComponentsInterface => $pages->components(' ')],
        'custom domains' => [fn(StatuspagesInterface $pages): CustomDomainsInterface => $pages->customDomains('')],
    ]);
});

describe('options', function (): void {
    it('asks for the resolved texts in the configured language', function (): void {
        [$client, $http] = fakeClient([MockResponse::json(['data' => StatuspageFixtures::page(['name' => 'Acme Status EN'])])], new ClientOptions(locale: 'en'));

        expect($client->statuspages()->get('p')->name)->toBe('Acme Status EN')
            ->and($http->lastRequest()?->header('Accept-Language'))->toBe('en');
    });
});
