<?php

declare(strict_types=1);

namespace LIVCK\Cloud;

use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\Me;
use LIVCK\Cloud\Data\Probe;
use LIVCK\Cloud\Http\Request;
use LIVCK\Cloud\Http\Response;
use LIVCK\Cloud\Resources\EnrollmentKeysInterface;
use LIVCK\Cloud\Resources\IncidentsInterface;
use LIVCK\Cloud\Resources\MaintenancesInterface;
use LIVCK\Cloud\Resources\ServicesInterface;
use LIVCK\Cloud\Resources\StatuspagesInterface;
use LIVCK\Cloud\Resources\TagsInterface;
use Psr\Http\Client\ClientInterface;

/**
 * What application code depends on. Resources are methods (not properties) so that a
 * decorator, a facade or a test double can implement the same contract.
 */
interface CloudClientInterface
{
    public function options(): ClientOptions;

    /** A copy with different options; this instance is untouched. */
    public function withOptions(ClientOptions $options): static;

    /** A copy sending `Accept-Language: $locale` (null removes the header). */
    public function withLocale(?string $locale): static;

    /** A copy talking through another PSR-18 client. */
    public function withHttpClient(ClientInterface $httpClient): static;

    /** Org-wide labels for services, e.g. one `customer:4711` per end customer. */
    public function tags(): TagsInterface;

    /**
     * Status pages, their components and custom domains, e.g. one page per end customer
     * with a group synced to the customer's tag.
     */
    public function statuspages(): StatuspagesInterface;

    /** Monitored services: creation, configuration, status, metrics and check history. */
    public function services(): ServicesInterface;

    /** Incidents, declared or detected (read-only in this version). */
    public function incidents(): IncidentsInterface;

    /** Maintenance windows (read-only in this version). */
    public function maintenances(): MaintenancesInterface;

    /**
     * The keys servers enroll with to be monitored by the server agent, e.g. one per server of
     * an end customer, carrying the customer's tag.
     */
    public function enrollmentKeys(): EnrollmentKeysInterface;

    /**
     * The calling token: type, abilities, organization and rate limit (`GET /v1/me`).
     * Reachable with any token; a good first call to verify a configuration.
     *
     * @throws Exceptions\ApiException
     * @throws Exceptions\TransportException
     */
    public function me(): Me;

    /**
     * The active monitoring locations (`GET /v1/probes`); their codes are what a service's
     * probe settings refer to. Needs `services.view`.
     *
     * @return list<Probe>
     *
     * @throws Exceptions\ApiException
     * @throws Exceptions\TransportException
     */
    public function probes(): array;

    /**
     * The check-type catalog (`GET /v1/meta/check-types`): per creatable type, the config
     * fields, condition fields with their operators, default conditions and interval range.
     * Pass it to `services()->create()` to validate a builder before sending. Needs
     * `services.view`.
     *
     * @throws Exceptions\ApiException
     * @throws Exceptions\TransportException
     */
    public function checkTypes(): CheckTypeCatalog;

    /**
     * Any endpoint, through the same transport as the resources: authentication, retries,
     * idempotency, error mapping. For what the SDK does not wrap yet.
     *
     * @param string $method GET, HEAD, POST, PUT, PATCH, DELETE or OPTIONS
     * @param string $path relative to the base URI, e.g. `services/{id}/uptime`
     * @param array<string, mixed> $query encoded as documented on {@see Request}
     * @param array<string, mixed>|null $json sent as `application/json` when not null
     * @param array<string, string> $headers extra headers; an `Idempotency-Key` here overrides the generated one
     *
     * @throws Exceptions\ApiException for a 4xx/5xx response
     * @throws Exceptions\TransportException when no response was obtained
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null, array $headers = []): Response;

    /**
     * Send a request built by hand, e.g. one with multipart parts.
     *
     * @throws Exceptions\ApiException for a 4xx/5xx response
     * @throws Exceptions\TransportException when no response was obtained
     */
    public function send(Request $request): Response;
}
