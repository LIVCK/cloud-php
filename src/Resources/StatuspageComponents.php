<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use LIVCK\Cloud\Builders\ComponentBuilder;
use LIVCK\Cloud\Data\StatuspageComponent;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Transport;
use LIVCK\Cloud\Payloads\UpdateComponent;
use LIVCK\Cloud\Support\Envelope;
use LIVCK\Cloud\Support\Path;

/**
 * `/v1/statuspages/{id}/components`, bound to one status page.
 */
final readonly class StatuspageComponents implements StatuspageComponentsInterface
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

        return Envelope::collection($response, StatuspageComponent::fromArray(...));
    }

    public function get(string $id): StatuspageComponent
    {
        $response = $this->transport->send(Request::get($this->path($id)));

        return Envelope::item($response, StatuspageComponent::fromArray(...));
    }

    public function create(ComponentBuilder $component, ?string $idempotencyKey = null): StatuspageComponent
    {
        $response = $this->transport->send(Request::post($this->path(), $component->toArray(), $idempotencyKey));

        return Envelope::item($response, StatuspageComponent::fromArray(...));
    }

    public function update(string $id, UpdateComponent $changes): StatuspageComponent
    {
        if ($changes->isEmpty()) {
            throw new InvalidArgumentException('UpdateComponent carries no changes; set at least one field first.');
        }

        $response = $this->transport->send(Request::patch($this->path($id), $changes->toArray()));

        return Envelope::item($response, StatuspageComponent::fromArray(...));
    }

    public function delete(string $id): void
    {
        $this->transport->send(Request::delete($this->path($id)));
    }

    private function path(string ...$segments): string
    {
        return Path::join('statuspages', $this->statuspageId, 'components', ...$segments);
    }
}
