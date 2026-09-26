<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Data\Maintenance;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Transport;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Query\MaintenanceQuery;
use LIVCK\Cloud\Support\Envelope;
use LIVCK\Cloud\Support\Path;

/**
 * `/v1/maintenances`, the read side.
 */
final readonly class Maintenances implements MaintenancesInterface
{
    public function __construct(
        private Transport $transport,
    ) {}

    public function list(?MaintenanceQuery $query = null): Page
    {
        $query ??= MaintenanceQuery::make();
        $response = $this->transport->send(Request::get('maintenances', $query->toArray()));

        return Envelope::page(
            $response,
            Maintenance::fromArray(...),
            fn(int $page): Page => $this->list($query->withPage($page)),
        );
    }

    public function each(?MaintenanceQuery $query = null): Generator
    {
        return $this->list($query)->lazy();
    }

    public function get(string $id): Maintenance
    {
        $response = $this->transport->send(Request::get(Path::join('maintenances', $id)));

        return Envelope::item($response, Maintenance::fromArray(...));
    }
}
