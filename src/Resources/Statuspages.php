<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Data\Statuspage;
use LIVCK\Cloud\Enums\AssetType;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Transport;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Payloads\AssetFile;
use LIVCK\Cloud\Payloads\CreateStatuspage;
use LIVCK\Cloud\Payloads\UpdateStatuspage;
use LIVCK\Cloud\Query\StatuspageQuery;
use LIVCK\Cloud\Support\Envelope;
use LIVCK\Cloud\Support\Path;

/**
 * `/v1/statuspages`, plus the components and custom domains nested below a page.
 */
final readonly class Statuspages implements StatuspagesInterface
{
    public function __construct(
        private Transport $transport,
    ) {}

    public function list(?StatuspageQuery $query = null): Page
    {
        $query ??= StatuspageQuery::make();
        $response = $this->transport->send(Request::get('statuspages', $query->toArray()));

        return Envelope::page(
            $response,
            Statuspage::fromArray(...),
            fn(int $page): Page => $this->list($query->withPage($page)),
        );
    }

    public function each(?StatuspageQuery $query = null): Generator
    {
        return $this->list($query)->lazy();
    }

    public function get(string $id): Statuspage
    {
        $response = $this->transport->send(Request::get(Path::join('statuspages', $id)));

        return Envelope::item($response, Statuspage::fromArray(...));
    }

    public function findBySlug(string $slug): ?Statuspage
    {
        if (trim($slug) === '') {
            throw new InvalidArgumentException('A statuspage slug must not be blank.');
        }

        return $this->list(StatuspageQuery::make()->withSlug($slug)->withPerPage(1))->first();
    }

    public function create(CreateStatuspage $page, ?string $idempotencyKey = null): Statuspage
    {
        $response = $this->transport->send(Request::post('statuspages', $page->toArray(), $idempotencyKey));

        return Envelope::item($response, Statuspage::fromArray(...));
    }

    public function update(string $id, UpdateStatuspage $changes): Statuspage
    {
        if ($changes->isEmpty()) {
            throw new InvalidArgumentException('UpdateStatuspage carries no changes; set at least one field first.');
        }

        $response = $this->transport->send(Request::patch(Path::join('statuspages', $id), $changes->toArray()));

        return Envelope::item($response, Statuspage::fromArray(...));
    }

    public function delete(string $id): void
    {
        $this->transport->send(Request::delete(Path::join('statuspages', $id)));
    }

    public function publish(string $id): Statuspage
    {
        $response = $this->transport->send(Request::post(Path::join('statuspages', $id, 'publish')));

        return Envelope::item($response, Statuspage::fromArray(...));
    }

    public function unpublish(string $id): Statuspage
    {
        $response = $this->transport->send(Request::post(Path::join('statuspages', $id, 'unpublish')));

        return Envelope::item($response, Statuspage::fromArray(...));
    }

    public function uploadAsset(string $id, AssetType $type, AssetFile $file): Statuspage
    {
        $request = Request::post($this->assetPath($id, $type))->withMultipart($file->toMultipartPart());

        return Envelope::item($this->transport->send($request), Statuspage::fromArray(...));
    }

    public function deleteAsset(string $id, AssetType $type): Statuspage
    {
        $response = $this->transport->send(Request::delete($this->assetPath($id, $type)));

        // The transport treats a 404 on a repeated DELETE as "the first attempt went through".
        // Not here: removing an asset answers 200 however often it runs, so a 404 means the
        // page itself is gone.
        if ($response->status() === 404) {
            throw ApiException::fromResponse($response);
        }

        return Envelope::item($response, Statuspage::fromArray(...));
    }

    public function components(string $statuspageId): StatuspageComponentsInterface
    {
        return new StatuspageComponents($this->transport, $statuspageId);
    }

    public function customDomains(string $statuspageId): CustomDomainsInterface
    {
        return new CustomDomains($this->transport, $statuspageId);
    }

    private function assetPath(string $id, AssetType $type): string
    {
        if ($type->isUnrecognized()) {
            throw new InvalidArgumentException('AssetType::Unrecognized cannot be sent; use Logo, LogoDark or Favicon.');
        }

        return Path::join('statuspages', $id, 'assets', $type->value);
    }
}
