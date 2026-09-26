<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * 401: the bearer token is missing, unknown, revoked or expired.
 */
class AuthenticationException extends ApiException {}
