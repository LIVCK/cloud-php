<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * `upsell.reason = "limit"` (402, or 403 for services and custom domains): a quantitative
 * plan limit is exhausted (services, custom domains, team seats, ...). Nothing was
 * created. The organization has to free up room or move to a larger plan.
 *
 * Not every plan cap is reported this way: the tag cap, the number of status pages and
 * of published ones, the minimum interval and the locations and conditions per service
 * are a {@see ValidationException} on their field.
 *
 * `limit` and `usage` are present when the route-level gate refused the request; a limit
 * enforced deeper in the domain (an on-call schedule, for instance) reports the key only.
 */
class PlanLimitException extends ApiException
{
    /** The exhausted limit, e.g. `services` or `tags`. */
    public function limitKey(): ?string
    {
        return $this->upsell()['key'] ?? null;
    }

    /** The plan's ceiling for that limit, when reported. */
    public function limit(): ?int
    {
        return $this->bodyInt('limit');
    }

    /** The organization's current usage, when reported. */
    public function usage(): ?int
    {
        return $this->bodyInt('usage');
    }
}
