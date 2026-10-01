<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Builders\EnrollmentKeyBuilder;
use LIVCK\Cloud\Data\CreatedEnrollmentKey;
use LIVCK\Cloud\Data\EnrollmentKey;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Transport;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Query\EnrollmentKeyQuery;
use LIVCK\Cloud\Support\Envelope;
use LIVCK\Cloud\Support\Path;

/**
 * `/v1/enrollment-keys`.
 */
final readonly class EnrollmentKeys implements EnrollmentKeysInterface
{
    public function __construct(
        private Transport $transport,
    ) {}

    public function list(?EnrollmentKeyQuery $query = null): Page
    {
        $query ??= EnrollmentKeyQuery::make();
        $response = $this->transport->send(Request::get('enrollment-keys', $query->toArray()));

        return Envelope::page(
            $response,
            EnrollmentKey::fromArray(...),
            fn(int $page): Page => $this->list($query->withPage($page)),
        );
    }

    public function each(?EnrollmentKeyQuery $query = null): Generator
    {
        return $this->list($query)->lazy();
    }

    public function get(string $id): EnrollmentKey
    {
        $response = $this->transport->send(Request::get(Path::join('enrollment-keys', $id)));

        return Envelope::item($response, EnrollmentKey::fromArray(...));
    }

    public function create(EnrollmentKeyBuilder $key, ?string $idempotencyKey = null): CreatedEnrollmentKey
    {
        $response = $this->transport->send(Request::post('enrollment-keys', $key->toArray(), $idempotencyKey));

        return Envelope::item($response, CreatedEnrollmentKey::fromArray(...));
    }

    public function revoke(string $id): void
    {
        $this->transport->send(Request::delete(Path::join('enrollment-keys', $id)));
    }
}
