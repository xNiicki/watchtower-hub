<?php

namespace App\Alerting\Conditions;

use App\Alerting\Breach;
use App\Models\Rule;
use Illuminate\Support\Collection;

interface Condition
{
    /**
     * Targets currently breaching this rule, with a description of each breach.
     *
     * @return Collection<int, Breach>
     */
    public function breachingTargets(Rule $rule): Collection;
}
