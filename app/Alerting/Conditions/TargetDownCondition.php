<?php

namespace App\Alerting\Conditions;

use App\Alerting\Breach;
use App\Enums\TargetStatus;
use App\Models\Rule;
use App\Models\Target;
use Illuminate\Support\Collection;

/**
 * Condition: target_down
 *
 * Breaches when a target's Check.status is Down.
 *
 * Params (all optional):
 *   - exclude_targets: list<string> — skip these target names
 *   - include_targets: list<string> — when present, ONLY these names are considered
 */
class TargetDownCondition implements Condition
{
    /**
     * @return Collection<int, Breach>
     */
    public function breachingTargets(Rule $rule): Collection
    {
        $params = $rule->params;
        $excludeNames = $params['exclude_targets'] ?? [];
        $includeNames = $params['include_targets'] ?? [];

        $query = Target::query()
            ->where('enabled', true)
            ->whereHas('check', fn ($q) => $q->where('status', TargetStatus::Down->value));

        if (! empty($includeNames)) {
            $query->whereIn('name', $includeNames);
        }

        if (! empty($excludeNames)) {
            $query->whereNotIn('name', $excludeNames);
        }

        return $query->get()->map(
            fn (Target $target) => new Breach(
                target: $target,
                description: "{$target->name} is down",
            )
        );
    }
}
