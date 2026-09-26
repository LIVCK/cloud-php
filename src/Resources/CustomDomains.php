<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use LIVCK\Cloud\Data\CustomDomain;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Transport;
use LIVCK\Cloud\Support\Envelope;
use LIVCK\Cloud\Support\Path;

/**
 * `/v1/statuspages/{id}/custom-domains`, bound to one status page.
 */
final readonly class CustomDomains implements CustomDomainsInterface
{
    public function __construct(
        private Transport $transport,
        private string $statuspageId,
    ) {
        if (trim($statuspageId) === '') {
            throw new InvalidArgumentException('A statuspage id must not be blank.');
        }
    }

    public function all(): array
    {
        $response = $this->transport->send(Request::get($this->path()));

        return Envelope::collection($response, CustomDomain::fromArray(...));
    }

    public function get(string $id): CustomDomain
    {
        $response = $this->transport->send(Request::get($this->path($id)));

        return Envelope::item($response, CustomDomain::fromArray(...));
    }

    public function attach(string $hostname, ?string $idempotencyKey = null): CustomDomain
    {
        if (trim($hostname) === '') {
            throw new InvalidArgumentException('A hostname must not be blank.');
        }

        $response = $this->transport->send(Request::post($this->path(), ['hostname' => $hostname], $idempotencyKey));

        return Envelope::item($response, CustomDomain::fromArray(...));
    }

    public function verify(string $id): CustomDomain
    {
        $response = $this->transport->send(Request::post($this->path($id, 'verify')));

        return Envelope::item($response, CustomDomain::fromArray(...));
    }

    public function detach(string $id): void
    {
        $this->transport->send(Request::delete($this->path($id)));
    }

    private function path(string ...$segments): string
    {
        return Path::join('statuspages', $this->statuspageId, 'custom-domains', ...$segments);
    }
}
