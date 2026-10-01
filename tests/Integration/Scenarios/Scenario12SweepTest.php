<?php

declare(strict_types=1);

use LIVCK\Cloud\CloudClientInterface;
use LIVCK\Cloud\Data\EnrollmentKey;
use LIVCK\Cloud\Enums\EnrollmentKeyStatus;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Query\EnrollmentKeyQuery;
use LIVCK\Cloud\Query\IncidentQuery;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Query\StatuspageQuery;
use LIVCK\Cloud\Query\TagQuery;
use LIVCK\Cloud\Support\Path;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * The safety net, last of all: whatever still carries the scenario prefix in either
 * organization (from this run or an earlier, interrupted one) is removed, in the order the
 * server allows: pages, then services (with what they carried alone), then the remaining
 * incidents and windows, then enrollment keys that can still enroll, then tags. Prints the
 * run's DTO audit at the end.
 */
$skip = LiveApi::skipReason();

/**
 * The enrollment keys a run left able to enroll a server: active, named with the scenario
 * prefix. Empty for a token without `agents.manage`.
 *
 * @return list<EnrollmentKey>
 */
function sweepableEnrollmentKeys(CloudClientInterface $client): array
{
    try {
        $active = iterator_to_array($client->enrollmentKeys()->each(
            EnrollmentKeyQuery::make()->withStatuses(EnrollmentKeyStatus::Active)->withPerPage(100),
        ), false);
    } catch (PermissionDeniedException) {
        return [];
    }

    return array_values(array_filter($active, static fn(EnrollmentKey $key): bool => Scenario::isOurs($key->name)));
}

describe('scenario 12: sweep', function () use ($skip): void {
    it('removes everything with the scenario prefix and reports the DTO audit', function (): void {
        $journey = new Journey();
        $clients = ['reseller organization' => LiveApi::client()];

        if (LiveApi::skipReason(LiveApi::OTHER_ORG_TOKEN_VARIABLE) === null) {
            $clients['other organization'] = LiveApi::client(LiveApi::OTHER_ORG_TOKEN_VARIABLE);
        }

        $swept = [];

        try {
            foreach ($clients as $label => $client) {
                $sweptHere = $journey->step(sprintf('sweep the %s', $label), static function () use ($client, $label): array {
                    $swept = [];
                    $gone = static function (string $what, Closure $remove) use (&$swept, $label): void {
                        try {
                            $remove();
                            $swept[] = $label . ': ' . $what;
                        } catch (NotFoundException) {
                            // Removed by a cascade a moment ago.
                        }
                    };

                    foreach (iterator_to_array($client->statuspages()->each(StatuspageQuery::make()->withPerPage(100)), false) as $page) {
                        if (Scenario::isOurs($page->slug)) {
                            $gone('status page ' . $page->slug, static fn() => $client->statuspages()->delete($page->id));
                        }
                    }

                    foreach (iterator_to_array($client->services()->each(ServiceQuery::make()->withPerPage(100)), false) as $service) {
                        if (Scenario::isOurs($service->name)) {
                            $gone('service ' . $service->name, static fn() => $client->services()->delete($service->id, deleteOrphanedIncidents: true, deleteOrphanedMaintenances: true));
                        }
                    }

                    foreach (iterator_to_array($client->incidents()->each(IncidentQuery::make()->withPerPage(100)), false) as $incident) {
                        if (Scenario::isOurs($incident->title)) {
                            $gone('incident ' . $incident->title, static function () use ($client, $incident): void {
                                $client->request('DELETE', Path::join('incidents', $incident->id));
                            });
                        }
                    }

                    foreach (iterator_to_array($client->maintenances()->each(MaintenanceQuery::make()->withPerPage(100)), false) as $window) {
                        if (Scenario::isOurs($window->title)) {
                            $gone('maintenance ' . $window->title, static function () use ($client, $window): void {
                                $client->request('DELETE', Path::join('maintenances', $window->id));
                            });
                        }
                    }

                    // Keys first: a tag an active key carries cannot be deleted. Revoked keys stay
                    // until the server deletes them; only a key that still enrolls is a leftover.
                    foreach (sweepableEnrollmentKeys($client) as $key) {
                        $gone('enrollment key ' . $key->name, static fn() => $client->enrollmentKeys()->revoke($key->id));
                    }

                    foreach (iterator_to_array($client->tags()->each(TagQuery::make()->withPerPage(100)), false) as $tag) {
                        if (Scenario::isOurs($tag->key)) {
                            $gone('tag ' . $tag->label, static fn() => $client->tags()->delete($tag->id));
                        }
                    }

                    return $swept;
                });
                $swept = [...$swept, ...$sweptHere];
            }

            $journey->step('nothing with the prefix is left in either organization', static function () use ($clients): void {
                foreach ($clients as $client) {
                    $left = [];

                    foreach ($client->services()->each(ServiceQuery::make()->withPerPage(100)) as $service) {
                        if (Scenario::isOurs($service->name)) {
                            $left[] = 'service ' . $service->name;
                        }
                    }

                    foreach ($client->tags()->each(TagQuery::make()->withPerPage(100)) as $tag) {
                        if (Scenario::isOurs($tag->key)) {
                            $left[] = 'tag ' . $tag->label;
                        }
                    }

                    foreach ($client->statuspages()->each(StatuspageQuery::make()->withPerPage(100)) as $page) {
                        if (Scenario::isOurs($page->slug)) {
                            $left[] = 'status page ' . $page->slug;
                        }
                    }

                    foreach (sweepableEnrollmentKeys($client) as $key) {
                        $left[] = 'enrollment key ' . $key->name;
                    }

                    expect($left)->toBe([]);
                }
            });

            $journey->note($swept === [] ? 'nothing was left behind by the scenarios' : 'left behind and swept: ' . implode(', ', $swept));
        } finally {
            Scenario::report($journey);
            fwrite(STDERR, "\n" . DtoAudit::report() . "\n");
        }
    })->skip($skip !== null, $skip ?? '');
});
