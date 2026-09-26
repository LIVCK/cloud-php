<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use LIVCK\Cloud\Data\CustomDomain;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\NotFoundException;
use LIVCK\Cloud\Exceptions\PlanLimitException;
use LIVCK\Cloud\Exceptions\RateLimitException;
use LIVCK\Cloud\Exceptions\ValidationException;

/**
 * The custom domains of one status page, from {@see StatuspagesInterface::customDomains()}.
 *
 * Attach a hostname, have the end customer publish the two records from
 * {@see CustomDomain::dnsRecords()}, and the server activates the domain on its own once it
 * sees them; {@see verify()} makes it look right away. Once the page's first domain is active,
 * the page's `url` uses it.
 *
 * Abilities: reads need `statuspages.view`, writes `statuspages.edit`. Detached domains, and
 * domains of other pages, read as not found.
 */
interface CustomDomainsInterface
{
    /**
     * The page's domains by hostname (not paginated), pending and failed ones included.
     *
     * @return list<CustomDomain>
     *
     * @throws NotFoundException when the status page does not exist
     * @throws ApiException
     */
    public function all(): array;

    /**
     * @throws NotFoundException
     * @throws ApiException
     */
    public function get(string $id): CustomDomain;

    /**
     * Attach a hostname (the server lower-cases and trims it). The returned domain is pending
     * and carries the records to publish.
     *
     * `$idempotencyKey` replaces the generated `Idempotency-Key` for this call: a stable
     * key (e.g. an order number) makes the attach safe to repeat after a crash, for 24
     * hours, per token.
     *
     * @throws InvalidArgumentException for a blank hostname or a malformed idempotency key (nothing is sent)
     * @throws PlanLimitException with key `custom_domains` when the page has as many domains as
     *                            the plan allows; failed domains count until detached
     * @throws ValidationException on `hostname` when it is malformed, belongs to LIVCK, or is
     *                             attached anywhere else, in any organization
     * @throws ApiException
     */
    public function attach(string $hostname, ?string $idempotencyKey = null): CustomDomain;

    /**
     * Check the DNS records now and return the domain with the outcome: active, or still
     * pending with `lastErrorCode` naming what is missing.
     *
     * Pending domains only. The server allows one check per domain every few seconds and
     * answers a quicker repeat with 429 and `Retry-After`; the client waits and tries again
     * on its own as long as the wait is within `ClientOptions::$maxRetryAfter` and retries are
     * left, and throws a {@see RateLimitException} otherwise.
     *
     * @throws NotFoundException
     * @throws ValidationException without field errors when the domain is not pending (active or failed)
     * @throws RateLimitException
     * @throws ApiException
     */
    public function verify(string $id): CustomDomain;

    /**
     * Release the hostname; the page stops answering under it at once. Attaching the same
     * hostname later creates a NEW domain, with a new id and a new TXT token, so the TXT
     * record has to be published again.
     *
     * @throws NotFoundException
     * @throws ApiException
     */
    public function detach(string $id): void;
}
