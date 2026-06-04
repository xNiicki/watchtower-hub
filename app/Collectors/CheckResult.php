<?php

namespace App\Collectors;

use App\Enums\TargetStatus;
use App\Models\Target;

readonly class CheckResult
{
    /**
     * @param  array<string, float>  $metrics
     */
    public function __construct(
        public Target $target,
        public TargetStatus $status,
        public ?int $latencyMs = null,
        public array $metrics = [],
    ) {}
}
