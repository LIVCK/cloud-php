<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * 403: the token exists but may not do this. Either it lacks the route's ability (the
 * permission set chosen when the token was minted, e.g. `services.edit`) or the
 * organization behind it is not available.
 *
 * A feature the plan does not include is also a 403, raised as the more specific
 * {@see FeatureNotAvailableException}.
 */
class PermissionDeniedException extends ApiException {}
