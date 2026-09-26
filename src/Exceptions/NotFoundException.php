<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * 404: no such resource in this organization. The API answers the same for an id that
 * does not exist, one that belongs to another organization and one the token's access
 * areas hide, so a 404 never reveals which of the three it was.
 */
class NotFoundException extends ApiException {}
