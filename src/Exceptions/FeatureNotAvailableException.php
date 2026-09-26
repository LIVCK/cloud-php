<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * 403 with `upsell.reason = "feature"`: the organization's plan does not include the
 * feature the endpoint needs (`api_access` on every v1 route, `custom_branding` on the
 * statuspage layout). Upgrading the plan is the only remedy; retrying is pointless.
 */
class FeatureNotAvailableException extends PermissionDeniedException
{
    /** The gated feature, e.g. `api_access`. */
    public function featureKey(): ?string
    {
        return $this->upsell()['key'] ?? null;
    }
}
