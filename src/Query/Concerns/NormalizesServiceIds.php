<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Query\Concerns;

use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * The `service_ids` scope filter shared by the incident and maintenance lists: ids or
 * Service DTOs in, a list of ids out, bounded by the server's ceiling of 100 per request.
 */
trait NormalizesServiceIds
{
    /**
     * @param array<array-key, Service|string> $services
     * @return list<string>
     */
    private static function serviceIdsOf(array $services, int $max): array
    {
        $ids = [];

        foreach ($services as $service) {
            $id = $service instanceof Service ? $service->id : $service;

            if (trim($id) === '') {
                throw new InvalidArgumentException('A service id in the service_ids filter must not be blank.');
            }

            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));

        if (count($ids) > $max) {
            throw new InvalidArgumentException(sprintf(
                'The service_ids filter takes at most %d ids per request (%d given); split the list into chunks of %d and merge the pages.',
                $max,
                count($ids),
                $max,
            ));
        }

        return $ids;
    }
}
