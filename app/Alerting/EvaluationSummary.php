<?php

namespace App\Alerting;

readonly class EvaluationSummary
{
    public function __construct(
        public int $pendingCreated,
        public int $fired,
        public int $resolved,
    ) {}
}
