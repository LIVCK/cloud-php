<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

use Throwable;

/**
 * Marker every exception thrown by this SDK implements.
 *
 * `catch (LivckCloudException $e)` covers transport failures, API errors, malformed
 * responses and client-side misuse alike; catch the concrete classes for anything finer.
 */
interface LivckCloudException extends Throwable {}
