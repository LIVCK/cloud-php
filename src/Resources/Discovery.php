<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\Me;
use LIVCK\Cloud\Data\Probe;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Transport;
use LIVCK\Cloud\Support\Envelope;

/**
 * The reference endpoints behind `CloudClient::me()`, `probes()` and `checkTypes()`:
 * the calling token, the monitoring locations and the check-type catalog. Nothing here is
 * organization data; `probes()` and `checkTypes()` need `services.view`, `me()` any token.
 */
final readonly class Discovery
{
    public function __construct(
        private Transport $transport,
    ) {}

    /** `GET /v1/me`: no `data` wrapper. */
    public function me(): Me
    {
        return Envelope::bare($this->transport->send(Request::get('me')), Me::fromArray(...));
    }

    /**
     * `GET /v1/probes`: the active locations, by name. Not paginated.
     *
     * @return list<Probe>
     */
    public function probes(): array
    {
        return Envelope::collection($this->transport->send(Request::get('probes')), Probe::fromArray(...));
    }

    /** `GET /v1/meta/check-types`. */
    public function checkTypes(): CheckTypeCatalog
    {
        return Envelope::item($this->transport->send(Request::get('meta/check-types')), CheckTypeCatalog::fromArray(...));
    }
}
