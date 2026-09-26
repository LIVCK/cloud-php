<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Exceptions;

/**
 * 5xx: the API or the edge in front of it failed. A 500 carries a generic message and
 * nothing else; a 502/504 may not even be JSON (an edge error page), in which case
 * {@see body()} is empty and `errorMessage()` is the HTTP reason phrase.
 *
 * Reads were retried before this surfaced; writes were not, because a 5xx after a
 * committed write must not be repeated blindly. Check the resource's state before
 * re-sending a write.
 */
class ServerException extends ApiException {}
