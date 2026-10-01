<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use Generator;
use LIVCK\Cloud\Builders\EnrollmentKeyBuilder;
use LIVCK\Cloud\Data\CreatedEnrollmentKey;
use LIVCK\Cloud\Data\EnrollmentKey;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\ValidationException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Query\EnrollmentKeyQuery;

/**
 * Enrollment keys: the credentials the install command gives a server so that it enrolls with
 * the organization and is monitored as an `agent` service.
 *
 * A reseller creates one `single` key per server of a customer, with the customer's tag, and
 * hands out the install command. The server enrolls with the key (the installer calls
 * `POST /v1/agents/enroll` with it, never with your API token) and carries the key's tags,
 * which the install cannot replace. Poll {@see get()} until `latestService()` names the new
 * server; the status only says whether the key can still enroll one. A key that can no longer
 * enroll is deleted once it is more than 30 days old.
 *
 * Abilities: every call needs `agents.manage`.
 */
interface EnrollmentKeysInterface
{
    /**
     * One page of keys, newest first, optionally in one state.
     *
     * @return Page<EnrollmentKey>
     *
     * @throws ApiException
     */
    public function list(?EnrollmentKeyQuery $query = null): Page;

    /**
     * Every matching key across all pages, one request per page as the iteration advances.
     *
     * @return Generator<int, EnrollmentKey>
     *
     * @throws ApiException
     */
    public function each(?EnrollmentKeyQuery $query = null): Generator;

    /**
     * A key with the servers enrolled with it.
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function get(string $id): EnrollmentKey;

    /**
     * Create a key. The result carries the key and the install command, this once: hand them on
     * right away.
     *
     * `$idempotencyKey` replaces the generated `Idempotency-Key` for this call: a stable
     * key (e.g. an order number) makes the create safe to repeat after a crash, for 24
     * hours, per token.
     *
     * @throws InvalidArgumentException for a malformed idempotency key (nothing is sent)
     * @throws ValidationException for an unknown tag id (on its entry, `tags.2`), tags that would put a server
     *                             under two rules of one kind, an expiry that is not in the future or beyond the
     *                             key type's lifetime, a use cap out of range, or agent tags allowed in a reseller
     *                             organization
     * @throws PermissionDeniedException when server monitoring is switched off for the organization
     * @throws ApiException
     */
    public function create(EnrollmentKeyBuilder $key, ?string $idempotencyKey = null): CreatedEnrollmentKey;

    /**
     * Revoke a key: it enrolls no further server, and the servers enrolled with it keep
     * running. Revoking a revoked key changes nothing.
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function revoke(string $id): void;
}
