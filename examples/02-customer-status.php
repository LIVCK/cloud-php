<?php

declare(strict_types=1);

/*
 * One customer at a glance: their services with the status everyone sees and the uptime of
 * the last 30 days, open incidents, active and upcoming maintenance windows, and the address
 * of their status page. Reads only.
 *
 *   php examples/02-customer-status.php <customer>
 *
 * Token abilities: services.view, incidents.view, maintenances.view, statuspages.view.
 */

use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Enums\MaintenanceStatus;
use LIVCK\Cloud\Exceptions\LivckCloudException;
use LIVCK\Cloud\Query\IncidentQuery;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Query\ServiceQuery;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/conventions.php';

$customer = trim($argv[1] ?? '');

if ($customer === '') {
    fail('Usage: php examples/02-customer-status.php <customer>');
}

$client = client('02-customer-status');

try {
    // The tag scopes everything: the customer's services are the ones carrying it.
    $services = iterator_to_array($client->services()->each(ServiceQuery::make()->withTag(customerLabel($customer))), false);
    usort($services, static fn(Service $a, Service $b): int => strcmp($a->name, $b->name));

    printf("Customer %s: %d service(s), uptime of the last 30 days\n", $customer, count($services));

    foreach ($services as $service) {
        $uptime = $service->uptime30d === null ? 'no data' : sprintf('%.2f%%', $service->uptime30d);
        printf("  %-12s %8s  %s\n", $service->effectiveStatus->value, $uptime, $service->name);
    }

    // Incidents and windows are filtered by service ids, at most 100 per request, so a large
    // customer takes one request per chunk. One incident can come back from several chunks;
    // keying by id keeps it once. (An empty id list would match nothing, but here it simply
    // means no chunk and no request.)
    $incidents = [];
    $windows = [];

    foreach (array_chunk($services, IncidentQuery::MAX_SERVICE_IDS) as $chunk) {
        $open = IncidentQuery::make()->withServiceIds($chunk)->withResolved(false);

        foreach ($client->incidents()->each($open) as $incident) {
            $incidents[$incident->id] = $incident;
        }

        $current = MaintenanceQuery::make()
            ->withServiceIds($chunk)
            ->withStatuses(MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress);

        foreach ($client->maintenances()->each($current) as $window) {
            $windows[$window->id] = $window;
        }
    }

    echo PHP_EOL, 'Open incidents (UTC): ', $incidents === [] ? 'none' : count($incidents), PHP_EOL;

    foreach ($incidents as $incident) {
        printf(
            "  since %s  %-13s %-8s %s%s\n",
            $incident->startedAt->format('Y-m-d H:i'),
            $incident->status->value,
            $incident->severity->value,
            $incident->title,
            $incident->isPublished ? '' : ' (internal, not on the status page)',
        );
    }

    echo PHP_EOL, 'Active and upcoming maintenance (UTC): ', $windows === [] ? 'none' : count($windows), PHP_EOL;

    foreach ($windows as $window) {
        printf(
            "  %s to %s  %-11s %s\n",
            $window->scheduledStart->format('Y-m-d H:i'),
            $window->scheduledEnd?->format('Y-m-d H:i') ?? 'open end',
            $window->status->value,
            $window->title,
        );
    }

    $page = $client->statuspages()->findBySlug(statuspageSlug($customer));

    echo PHP_EOL, 'Status page: ', match (true) {
        $page === null => 'none yet (see 01-onboard-customer.php)',
        $page->isPublished => $page->url ?? $page->slug,
        default => ($page->url ?? $page->slug) . ' (unpublished)',
    }, PHP_EOL;
} catch (LivckCloudException $e) {
    fail($e->getMessage());
}
