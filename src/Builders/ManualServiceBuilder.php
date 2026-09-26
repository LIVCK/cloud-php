<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

/**
 * A service nothing probes. It has no target and no monitoring settings; its status is
 * set by hand ({@see \LIVCK\Cloud\Resources\ServicesInterface::applyStatusOverride()}) and
 * reads healthy (`up`) until then. Only name, tags and the escape hatches apply.
 */
final class ManualServiceBuilder extends ServiceBuilder {}
