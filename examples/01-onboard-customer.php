<?php

declare(strict_types=1);

/*
 * Onboard an end customer: their tag, their status page with a group that follows the tag,
 * and the checks for their website. Everything is looked up before it is created, so a
 * second run, after a crash or just by accident, finds what the first one made.
 *
 *   php examples/01-onboard-customer.php <customer> <website-url> [custom-domain]
 *   php examples/01-onboard-customer.php 4711 https://www.example.com status.example.com
 *
 * With a custom domain, the script prints the two DNS records to hand to the customer.
 *
 * Token abilities: services.view, services.create, services.edit, statuspages.view,
 * statuspages.create, statuspages.edit.
 */

use LIVCK\Cloud\Builders\ComponentBuilder;
use LIVCK\Cloud\Exceptions\LivckCloudException;
use LIVCK\Cloud\Exceptions\PlanLimitException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Payloads\CreateStatuspage;
use LIVCK\Cloud\Query\ServiceQuery;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/conventions.php';

$customer = trim($argv[1] ?? '');
$url = normalizeUrl($argv[2] ?? '');
$hostname = isset($argv[3]) ? strtolower(trim($argv[3])) : null;

if ($customer === '' || $url === null || $hostname === '') {
    fail('Usage: php examples/01-onboard-customer.php <customer> <website-url> [custom-domain]');
}

function report(bool $created, string $what, string $detail): void
{
    printf("  %-8s %-14s %s\n", $created ? 'created' : 'found', $what, $detail);
}

$client = client('01-onboard-customer');

try {
    echo 'Customer ', $customer, PHP_EOL;

    // The synced group below names the tag by id, so fetch the tag itself (services alone would
    // take its name, `customer:4711`). Find or create: the tag exists afterwards either way, even
    // when two runs race, so this call needs no stable Idempotency-Key of its own.
    $ensured = $client->tags()->ensure(CUSTOMER_TAG_KEY, $customer);
    $tag = $ensured->tag;
    report($ensured->created, 'tag', $tag->label);

    // The status page, recognised by its slug. It goes online right away when the plan has a
    // free published slot, and is created unpublished otherwise. Every create below carries a
    // stable Idempotency-Key per customer and purpose (conventions.php): a rerun within 24
    // hours of a crash gets the first attempt's answer instead of a second copy.
    $page = $client->statuspages()->findBySlug(statuspageSlug($customer));
    $created = $page === null;
    $page ??= $client->statuspages()->create(
        CreateStatuspage::make('Status ' . $customer)->withSlug(statuspageSlug($customer)),
        idempotencyKey($customer, 'statuspage'),
    );
    report($created, 'status page', $page->slug);

    // One group that follows the tag: every service tagged with it, now or later, appears on
    // the page by itself and disappears when the tag or the service goes.
    $components = $client->statuspages()->components($page->id);
    $group = null;

    foreach ($components->all() as $component) {
        if ($component->isSyncedGroup() && $component->syncTagId === $tag->id) {
            $group = $component;

            break;
        }
    }

    $created = $group === null;
    $group ??= $components->create(ComponentBuilder::syncedGroup('Services', $tag)->showUptimeBars(), idempotencyKey($customer, 'group'));
    report($created, 'synced group', $group->name);

    // The website's checks, matched by type and target against what the tag already holds.
    $existing = [];

    foreach ($client->services()->each(ServiceQuery::make()->withTag($tag)) as $service) {
        $existing[monitorKey($service->checkType, $service->target)] ??= $service;
    }

    $catalog = $client->checkTypes();

    foreach (websiteMonitors($url) as $key => $monitor) {
        // The key names the monitor (type and target), so two websites of one customer never
        // share one. Reruns are safe anyway because of the lookup.
        $service = $existing[$key] ?? $client->services()->create($monitor->tags($tag), $catalog, idempotencyKey($customer, $key));
        report(! isset($existing[$key]), $service->checkType->value . ' check', (string) $service->target);
    }

    if ($hostname !== null) {
        $domains = $client->statuspages()->customDomains($page->id);
        $domain = null;

        foreach ($domains->all() as $candidate) {
            if ($candidate->hostname === $hostname) {
                $domain = $candidate;

                break;
            }
        }

        $created = $domain === null;
        $domain ??= $domains->attach($hostname, idempotencyKey($customer, 'domain-' . $hostname));
        $state = $domain->status->value . ($domain->lastErrorCode === null ? '' : ', ' . $domain->lastErrorCode->value);
        report($created, 'custom domain', sprintf('%s (%s)', $domain->hostname, $state));

        if (! $domain->isActive()) {
            echo PHP_EOL, 'DNS records for the customer; the domain goes live once LIVCK sees them:', PHP_EOL;

            foreach ($domain->dnsRecords() as $record) {
                printf("  %-5s %s  ->  %s\n", $record->type, $record->name, $record->value);
            }
        }
    }

    // The address switches to the custom domain once that is verified.
    printf("\nStatus page: %s%s\n", $page->url ?? '-', $page->isPublished ? '' : ' (unpublished: no free published slot on the plan)');
} catch (PlanLimitException $e) {
    fail(sprintf('Plan limit "%s" reached (%s of %s used). Upgrade the plan or free up room.', $e->limitKey() ?? '?', $e->usage() ?? '?', $e->limit() ?? '?'));
} catch (ValidationException $e) {
    // Also how the tag cap, the number of status pages and a taken slug are reported.
    fail('Rejected: ' . ($e->firstError() ?? $e->errorMessage()));
} catch (LivckCloudException $e) {
    fail($e->getMessage());
}
