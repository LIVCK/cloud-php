<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Resources;

use LIVCK\Cloud\Builders\HeartbeatDeclaration;
use LIVCK\Cloud\Data\HeartbeatDeclarationResult;
use LIVCK\Cloud\Exceptions\ApiException;
use LIVCK\Cloud\Exceptions\FeatureNotAvailableException;
use LIVCK\Cloud\Exceptions\HeartbeatDeclarationConflictException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\PermissionDeniedException;
use LIVCK\Cloud\Exceptions\RestrictedViewException;
use LIVCK\Cloud\Exceptions\ValidationException;

/**
 * Heartbeats declared from code: an application names the heartbeats it expects, and LIVCK keeps
 * them in line with what it names. A declared heartbeat is a service like any other; read,
 * pause or delete it through {@see ServicesInterface} by its `serviceId`.
 *
 * Abilities: `heartbeats.declare` (a token that may create and edit services has it too). The
 * token must see the whole organization: a token whose view is limited by access areas is
 * refused with {@see RestrictedViewException}.
 */
interface HeartbeatsInterface
{
    /**
     * Declare every heartbeat one application expects in one environment. LIVCK finds each by
     * application, environment and key: an unknown key is created, a changed declaration updates
     * the heartbeat (fields that were set; the rest keeps its value), an unchanged one is left
     * alone. The call is meant to be repeated, on every deploy or start.
     *
     * Nothing is ever removed or paused. A heartbeat declared earlier that the list no longer
     * names keeps expecting pings and is named under `unlisted`; delete it once its job is gone.
     *
     * New heartbeats count as services. When the plan or the creation limit has no room for all
     * of them, none is created and `notCreated` says why; the existing ones are aligned anyway and
     * the call succeeds. Check {@see HeartbeatDeclarationResult::allExist()}.
     *
     * `$idempotencyKey` replaces the generated `Idempotency-Key` for this call: a stable
     * key (e.g. an order number) makes the create safe to repeat after a crash, for 24
     * hours, per token.
     *
     * @throws InvalidArgumentException for a malformed idempotency key (nothing is sent)
     * @throws ValidationException for a malformed application, environment, key, schedule or bound, a list of
     *                             more than 400 heartbeats, or a declaration that would bring the organization
     *                             its 51st application or 21st environment (on `app` / `environment`)
     * @throws HeartbeatDeclarationConflictException when another declaration of the same heartbeats ran at the
     *                                               same time; send it again
     * @throws FeatureNotAvailableException when the plan does not include API access
     * @throws RestrictedViewException when the token's view is limited by access areas; a token created by a
     *                                 member who sees the whole organization is needed, more abilities do not help
     * @throws PermissionDeniedException when the token lacks `heartbeats.declare`
     * @throws ApiException
     */
    public function declare(HeartbeatDeclaration $declaration, ?string $idempotencyKey = null): HeartbeatDeclarationResult;
}
