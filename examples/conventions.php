<?php

declare(strict_types=1);

/*
 * What the reseller scripts agree on, so that one of them finds what another created: the
 * tag per end customer, the slug of the customer's status page, and what is monitored per
 * website. Adjust these to your own setup, not the scripts.
 */

use LIVCK\Cloud\Builders\Conditions\HttpCondition;
use LIVCK\Cloud\Builders\Conditions\SslCondition;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\CheckType;

/** The key of the one tag per end customer: `customer:4711`. */
const CUSTOMER_TAG_KEY = 'customer';

/** Slugs are unique across all LIVCK organizations, so put your brand in front: `acme-4711`. */
const STATUSPAGE_SLUG_PREFIX = 'acme';

/** The customer's tag label; also what the service list filters by. */
function customerLabel(string $customer): string
{
    return CUSTOMER_TAG_KEY . ':' . $customer;
}

/**
 * Derived from the customer number and never stored, so every script finds the same page
 * through `$client->statuspages()->findBySlug()`.
 */
function statuspageSlug(string $customer): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(STATUSPAGE_SLUG_PREFIX . '-' . $customer)), '-');
}

/**
 * The Idempotency-Key of one create while onboarding a customer (`onboard-4711-statuspage`):
 * a rerun after a crash gets the first attempt's answer instead of a second copy. The API
 * keeps a key for 24 hours per token, so onboarding a customer again within a day of
 * offboarding needs another key; put your order number in it. A key is a header value:
 * visible ASCII only (hence the encoding) and at most 255 characters (hence the hash).
 */
function idempotencyKey(string $customer, string $purpose): string
{
    $key = rawurlencode('onboard-' . $customer . '-' . $purpose);

    return strlen($key) <= 255 ? $key : 'onboard-' . sha1($customer . '-' . $purpose);
}

/**
 * What is monitored per website: the URL, and for https on the default port its
 * certificate. Keyed by {@see monitorKey()}; the caller adds the customer's tag.
 *
 * @return array<string, ServiceBuilder>
 */
function websiteMonitors(string $url): array
{
    $url = normalizeUrl($url) ?? throw new InvalidArgumentException(sprintf('"%s" is not an http(s) URL.', $url));
    $host = (string) parse_url($url, PHP_URL_HOST);

    $monitors = [
        monitorKey(CheckType::Http, $url) => ServiceBuilder::http($host . rtrim((string) parse_url($url, PHP_URL_PATH), '/'), $url)
            ->interval(60)
            ->condition(
                HttpCondition::statusCode()->gte(400)->down(),
                HttpCondition::responseTimeMs()->gt(5000)->degraded(),
            ),
    ];

    if (str_starts_with($url, 'https://') && parse_url($url, PHP_URL_PORT) === null) {
        $monitors[monitorKey(CheckType::Ssl, $host)] = ServiceBuilder::ssl($host . ' certificate', $host)
            ->condition(
                SslCondition::daysUntilExpiry()->lt(14)->degraded(),
                SslCondition::daysUntilExpiry()->lt(3)->down(),
            );
    }

    return $monitors;
}

/** How the scripts recognise a monitor again: check type plus normalized target. */
function monitorKey(CheckType $type, ?string $target): string
{
    $target ??= '';

    return $type->value . ' ' . match ($type) {
        CheckType::Http => normalizeUrl($target) ?? $target,
        CheckType::Ssl => strtolower($target),
        default => $target,
    };
}

/**
 * The API stores a target exactly as sent, so compare websites in one form: scheme and host
 * lower-cased, no lone trailing slash. Null for anything but an http(s) URL.
 */
function normalizeUrl(string $url): ?string
{
    $parts = parse_url(trim($url));

    if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    $scheme = strtolower($parts['scheme']);

    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $path = $parts['path'] ?? '';

    return $scheme . '://' . strtolower($parts['host'])
        . (isset($parts['port']) ? ':' . $parts['port'] : '')
        . ($path === '/' ? '' : $path)
        . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

/**
 * Whether another customer's tag is on the service as well. Such a service is shared:
 * letting go of it for one customer means removing that customer's tag, not deleting it.
 */
function sharedWithOthers(Service $service, string $customer): bool
{
    foreach (tagsWithout($service, $customer) as $tag) {
        if ($tag->key === CUSTOMER_TAG_KEY) {
            return true;
        }
    }

    return false;
}

/**
 * The service's tags without the customer's, for `UpdateService::withTags()`, which
 * replaces the whole set.
 *
 * @return list<Tag>
 */
function tagsWithout(Service $service, string $customer): array
{
    $label = customerLabel($customer);

    return array_values(array_filter($service->tags ?? [], static fn(Tag $tag): bool => $tag->label !== $label));
}
