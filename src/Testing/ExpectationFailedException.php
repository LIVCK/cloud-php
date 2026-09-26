<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Testing;

use AssertionError;

/**
 * A {@see FakeHttpClient} assertion did not hold. Extends `AssertionError`, which
 * PHPUnit (and therefore Pest) reports as a failed test rather than an error.
 */
final class ExpectationFailedException extends AssertionError {}
