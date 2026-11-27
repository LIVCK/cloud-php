<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * 403 with `code = "restricted_view"`: the token sees only part of the organization. Its view is
 * limited by access areas, because it sees what the member who created it sees (and nothing once
 * that member has left), and the endpoint needs the whole organization. Today that is
 * `POST /v1/heartbeats/declare`: declared heartbeats belong to the organization, not to an area.
 *
 * More abilities do not help, and neither does retrying: use a token created by a member who sees
 * the whole organization. A 403 for a missing ability is the plain
 * {@see PermissionDeniedException}.
 */
final class RestrictedViewException extends PermissionDeniedException
{
    /** The machine-readable `code` the server sends with this refusal. */
    public const string CODE = 'restricted_view';
}
