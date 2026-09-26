<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Data\Incident;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Transport;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Query\IncidentQuery;
use LIVCK\Cloud\Support\Envelope;
use LIVCK\Cloud\Support\Path;

/**
 * `/v1/incidents`, the read side.
 */
final readonly class Incidents implements IncidentsInterface
{
    public function __construct(
        private Transport $transport,
    ) {}

    public function list(?IncidentQuery $query = null): Page
    {
        $query ??= IncidentQuery::make();
        $response = $this->transport->send(Request::get('incidents', $query->toArray()));

        return Envelope::page(
            $response,
            Incident::fromArray(...),
            fn(int $page): Page => $this->list($query->withPage($page)),
        );
    }

    public function each(?IncidentQuery $query = null): Generator
    {
        return $this->list($query)->lazy();
    }

    public function get(string $id): Incident
    {
        $response = $this->transport->send(Request::get(Path::join('incidents', $id)));

        return Envelope::item($response, Incident::fromArray(...));
    }
}
