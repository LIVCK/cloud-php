<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * The SDK was used incorrectly on the client side: an option out of range, a malformed
 * idempotency key, an enum case that cannot be sent, a query value that has no wire
 * representation. Nothing was sent to the API.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements LivckCloudException {}
