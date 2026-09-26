<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

use InvalidArgumentException as BaseInvalidArgumentException;

/**
 * A service payload does not match the check-type catalog (`GET /v1/meta/check-types`):
 * an unknown config key, a value that is not one of a select's options, an unknown
 * condition field, an operator the field does not take, or an interval outside the type's
 * range. Every problem is listed; nothing was sent.
 *
 * The check is opt-in ({@see \LIVCK\Cloud\Builders\ServiceBuilder::validate()}) and driven
 * by the catalog's data alone, so a server-side addition passes without an SDK update.
 */
final class CatalogValidationException extends BaseInvalidArgumentException implements LivckCloudException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(
        private readonly array $problems,
    ) {
        parent::__construct(sprintf(
            "The service payload does not match the check-type catalog:\n - %s",
            implode("\n - ", $problems),
        ));
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }
}
