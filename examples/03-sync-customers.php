<?php

declare(strict_types=1);

/*
 * Bring LIVCK in line with the websites your billing system says each customer has: create
 * the checks that are missing, delete those no longer wanted, leave everything else alone.
 * Safe to run from cron; a run with nothing to do changes nothing. Try --dry-run first.
 *
 *   php examples/03-sync-customers.php [--dry-run] [desired-state.json]
 *
 * The desired state is a JSON object of customer number => website URLs, like customers.json
 * next to this script (the default). What the sync may touch:
 *
 *  - Only the customers listed. A customer tag missing from the list is reported, never
 *    emptied, so a truncated export cannot wipe out monitoring; offboarding is
 *    04-offboard-customer.php. A customer listed without websites loses all of them.
 *  - Only services tagged customer:<number>, and of those only the HTTP and SSL checks it
 *    provisions per website (conventions.php). Anything else under the tag stays.
 *  - A service that another customer's tag also holds is not deleted; it only loses the tag.
 *
 * Status pages follow by themselves: a customer's synced group picks up new services and
 * drops deleted ones. Beyond 120 requests a minute the client waits out the rate limit on
 * its own, so a large sync slows down instead of failing.
 *
 * Token abilities: services.view, services.create, services.edit, services.delete.
 */

use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Exceptions\LivckCloudException;
use LIVCK\Cloud\Exceptions\PlanLimitException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Query\TagQuery;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/conventions.php';

/**
 * The desired state, checked completely before anything is sent: a list of customer and
 * normalized URLs. (A list rather than a map, because PHP turns a key like "4711" into an
 * integer.)
 *
 * @return list<array{string, list<string>}>
 */
function desiredState(string $file): array
{
    $json = is_file($file) ? file_get_contents($file) : false;

    if ($json === false) {
        fail(sprintf('Cannot read %s.', $file));
    }

    try {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fail(sprintf('%s is not valid JSON: %s', $file, $e->getMessage()));
    }

    if (! is_array($data) || ($data !== [] && array_is_list($data))) {
        fail(sprintf('%s must hold an object of customer number => list of website URLs.', $file));
    }

    $desired = [];

    foreach ($data as $customer => $urls) {
        $customer = trim((string) $customer);

        if ($customer === '' || ! is_array($urls)) {
            fail(sprintf('Customer "%s": expected a list of website URLs.', $customer));
        }

        $normalized = [];

        foreach ($urls as $url) {
            $normalized[] = (is_string($url) ? normalizeUrl($url) : null)
                ?? fail(sprintf('Customer %s: %s is not an http(s) URL.', $customer, json_encode($url)));
        }

        $desired[] = [$customer, array_values(array_unique($normalized))];
    }

    return $desired;
}

$dryRun = in_array('--dry-run', $argv, true);
$files = array_values(array_filter(array_slice($argv, 1), static fn(string $arg): bool => ! str_starts_with($arg, '--')));
$desired = desiredState($files[0] ?? __DIR__ . '/customers.json');

$client = client('03-sync-customers');
$counts = ['created' => 0, 'deleted' => 0, 'untagged' => 0, 'unchanged' => 0];

try {
    $catalog = $client->checkTypes();

    foreach ($desired as [$customer, $urls]) {
        $label = customerLabel($customer);
        echo $label, PHP_EOL;

        $wanted = [];

        foreach ($urls as $url) {
            $wanted += websiteMonitors($url);
        }

        // What the tag holds today. Only the check types the sync provisions are its business.
        $present = [];
        $unwanted = [];

        foreach ($client->services()->each(ServiceQuery::make()->withTag($label)) as $service) {
            if ($service->checkType !== CheckType::Http && $service->checkType !== CheckType::Ssl) {
                continue;
            }

            $key = monitorKey($service->checkType, $service->target);

            if (isset($wanted[$key])) {
                $present[$key] = true;
                $counts['unchanged']++;
            } else {
                $unwanted[] = $service;
            }
        }

        $tag = null;

        foreach (array_diff_key($wanted, $present) as $monitor) {
            printf("  + create  %-4s %s\n", $monitor->checkType()->value, $monitor->target());
            $counts['created']++;

            if (! $dryRun) {
                // Only now does the customer need a tag; ensure() finds or creates it.
                $tag ??= $client->tags()->ensure(CUSTOMER_TAG_KEY, $customer)->tag;

                // The generated Idempotency-Key covers the retries of this call. A stable key
                // (as in 01-onboard-customer.php) would be wrong for a sync: for 24 hours it
                // would replay an earlier run's create instead of recreating a service that
                // went away in between.
                $client->services()->create($monitor->tags($tag), $catalog);
            }
        }

        foreach ($unwanted as $service) {
            $shared = sharedWithOthers($service, $customer);
            printf("  - %-6s  %-4s %s\n", $shared ? 'untag' : 'delete', $service->checkType->value, $service->target);
            $counts[$shared ? 'untagged' : 'deleted']++;

            if ($dryRun) {
                continue;
            }

            if ($shared) {
                $client->services()->update($service->id, UpdateService::make()->withTags(...tagsWithout($service, $customer)));
            } else {
                $client->services()->delete($service->id);
            }
        }
    }

    // Customers LIVCK knows that the desired state does not mention: reported, not touched.
    $listed = array_column($desired, 0);

    foreach ($client->tags()->each(TagQuery::make()->withKey(CUSTOMER_TAG_KEY)) as $customerTag) {
        if ($customerTag->value !== null && ! in_array($customerTag->value, $listed, true)) {
            printf("%s is not in the desired state, left alone (%d service(s))\n", $customerTag->label, $customerTag->servicesCount ?? 0);
        }
    }

    printf(
        $dryRun
            ? "\nDry run, nothing changed: %d to create, %d to delete, %d to untag, %d unchanged\n"
            : "\nDone: %d created, %d deleted, %d untagged, %d unchanged\n",
        $counts['created'],
        $counts['deleted'],
        $counts['untagged'],
        $counts['unchanged'],
    );
} catch (PlanLimitException $e) {
    fail(sprintf('Stopped at plan limit "%s" (%s of %s used); the next run continues once there is room.', $e->limitKey() ?? '?', $e->usage() ?? '?', $e->limit() ?? '?'));
} catch (ValidationException $e) {
    fail('Rejected: ' . ($e->firstError() ?? $e->errorMessage()));
} catch (LivckCloudException $e) {
    // Whatever went through stays; the next run picks up the rest.
    fail($e->getMessage());
}
