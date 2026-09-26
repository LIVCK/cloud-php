<?php

declare(strict_types=1);

/*
 * Offboard an end customer: their services, then their status page, then their tag. Without
 * --confirm the script only lists what would go. After a failure, run it again: it picks up
 * whatever is left.
 *
 *   php examples/04-offboard-customer.php <customer> [--confirm]
 *
 * A service that another customer's tag also holds is not deleted; it only loses this
 * customer's tag.
 *
 * Token abilities: services.view, services.edit, services.delete, statuspages.view,
 * statuspages.delete.
 */

use LIVCK\Cloud\Exceptions\LivckCloudException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Payloads\UpdateService;
use LIVCK\Cloud\Query\ServiceQuery;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/conventions.php';

$arguments = array_values(array_filter(array_slice($argv, 1), static fn(string $arg): bool => ! str_starts_with($arg, '--')));
$customer = trim($arguments[0] ?? '');
$confirmed = in_array('--confirm', $argv, true);

// A blank customer would make the label "customer:", which names a different tag. Refuse it.
if ($customer === '') {
    fail('Usage: php examples/04-offboard-customer.php <customer> [--confirm]');
}

$client = client('04-offboard-customer');
$label = customerLabel($customer);

try {
    // Collect first, delete after: deleting while paging through the list would shift the
    // pages under the loop and skip services.
    $services = iterator_to_array($client->services()->each(ServiceQuery::make()->withTag($label)), false);
    $page = $client->statuspages()->findBySlug(statuspageSlug($customer));
    $tag = $client->tags()->findByLabel($label);

    if ($services === [] && $page === null && $tag === null) {
        echo 'Nothing to offboard for customer ', $customer, '.', PHP_EOL;

        exit(0);
    }

    echo 'Offboarding customer ', $customer, PHP_EOL;

    foreach ($services as $service) {
        printf("  %-7s service      %s %s\n", sharedWithOthers($service, $customer) ? 'untag' : 'delete', $service->checkType->value, $service->target ?? $service->name);
    }

    if ($page !== null) {
        printf("  delete  status page  %s\n", $page->url ?? $page->slug);
    }

    if ($tag !== null) {
        printf("  delete  tag          %s\n", $tag->label);
    }

    if (! $confirmed) {
        echo 'Nothing deleted. Run again with --confirm to go ahead.', PHP_EOL;

        exit(0);
    }

    foreach ($services as $service) {
        if (sharedWithOthers($service, $customer)) {
            $client->services()->update($service->id, UpdateService::make()->withTags(...tagsWithout($service, $customer)));

            continue;
        }

        // Incidents and maintenance windows that also cover other services only lose this
        // one. Those it carried alone are closed, not erased: open incidents are resolved and
        // scheduled or running windows cancelled, so the history stays. To erase them, pass
        // deleteOrphanedIncidents: true and deleteOrphanedMaintenances: true; each needs the
        // ability incidents.delete or maintenances.delete, else the call is refused (403)
        // and nothing is touched.
        $client->services()->delete($service->id);
    }

    // Takes the page's components and subscribers along and releases its custom domains.
    if ($page !== null) {
        $client->statuspages()->delete($page->id);
    }

    // Last: a synced group or an SLA objective that still uses the tag blocks its deletion.
    if ($tag !== null) {
        $client->tags()->delete($tag->id);
    }

    echo 'Done.', PHP_EOL;
} catch (ValidationException $e) {
    fail('Refused: ' . ($e->firstError() ?? $e->errorMessage()));
} catch (LivckCloudException $e) {
    fail($e->getMessage());
}
