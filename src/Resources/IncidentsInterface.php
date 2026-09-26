<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Data\Incident;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Query\IncidentQuery;

/**
 * Incidents, read-only in this version: declared by hand, detected by the probes, or a
 * standing notice. Reads need `incidents.view`.
 *
 * Internal incidents (`is_published: false`) are listed like published ones; filter with
 * {@see IncidentQuery::withPublished()} where only what a statuspage shows matters.
 */
interface IncidentsInterface
{
    /**
     * One page of incidents, newest first (by `started_at`); the affected services are
     * included, the timeline is not.
     *
     * @return Page<Incident>
     *
     * @throws ValidationException for a malformed filter
     * @throws ApiException
     */
    public function list(?IncidentQuery $query = null): Page;

    /**
     * Every matching incident across all pages, one request per page as the iteration advances.
     *
     * @return Generator<int, Incident>
     *
     * @throws ApiException
     */
    public function each(?IncidentQuery $query = null): Generator;

    /**
     * One incident with its timeline (`updates`) and affected services.
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function get(string $id): Incident;
}
