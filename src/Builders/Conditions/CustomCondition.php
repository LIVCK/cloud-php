<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders\Conditions;

/**
 * A condition on a field the SDK has no typed method for yet, accepted by every builder.
 * Built with {@see Condition::custom()}.
 */
final readonly class CustomCondition extends Condition {}
