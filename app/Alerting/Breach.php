<?php

namespace App\Alerting;

use App\Models\Target;

/**
 * A value object representing a target that is currently breaching a rule,
 * along with a human-readable description of the breach condition.
 */
readonly class Breach
{
    public function __construct(
        public Target $target,
        public string $description,
    ) {}
}
