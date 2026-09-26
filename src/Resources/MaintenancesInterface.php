<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Data\Maintenance;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Query\MaintenanceQuery;

/**
 * Maintenance windows, read-only in this version. Reads need `maintenances.view`.
 * Archived windows are never listed.
 */
interface MaintenancesInterface
{
    /**
     * One page of windows, newest scheduled start first; the covered services are included,
     * statuspages and timeline are not.
     *
     * @return Page<Maintenance>
     *
     * @throws ValidationException for a malformed filter or an unknown status
     * @throws ApiException
     */
    public function list(?MaintenanceQuery $query = null): Page;

    /**
     * Every matching window across all pages, one request per page as the iteration advances.
     *
     * @return Generator<int, Maintenance>
     *
     * @throws ApiException
     */
    public function each(?MaintenanceQuery $query = null): Generator;

    /**
     * One window with its statuspages, services and timeline (`updates`).
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function get(string $id): Maintenance;
}
