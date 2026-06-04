<?php

namespace App\Alerting\Conditions;

use App\Alerting\Breach;
use App\Models\Rule;
use App\Models\Target;
use App\Services\LatestMetrics;
use Illuminate\Support\Collection;

/**
 * Condition: metric_threshold
 *
 * Breaches when the latest Metric row for a given key, within the staleness
 * window, satisfies the operator comparison against the configured threshold.
 *
 * Params (required unless noted):
 *   - metric: string — metric key to evaluate (e.g. 'disk_pct')
 *   - operator: '>' | '>=' | '<' | '<=' — comparison operator
 *   - value: float — threshold value
 *   - staleness_minutes: int (optional, default 15) — ignore metrics older than this
 */
class MetricThresholdCondition implements Condition
{
    public function __construct(private readonly LatestMetrics $latestMetrics) {}

    /**
     * @return Collection<int, Breach>
     */
    public function breachingTargets(Rule $rule): Collection
    {
        $params = $rule->params;
        $metricKey = $params['metric'];
        $operator = $params['operator'];
        $threshold = (float) $params['value'];
        $stalenessMinutes = (int) ($params['staleness_minutes'] ?? 15);

        // Fetch only enabled-target IDs to scope the metrics query.
        $targetIds = Target::where('enabled', true)->pluck('id');

        $latestByTarget = $this->latestMetrics->forTargets($targetIds, [$metricKey], $stalenessMinutes);

        // Filter targets that breach the threshold.
        $breachingTargetIds = [];
        $valueByTargetId = [];

        foreach ($latestByTarget as $targetId => $metrics) {
            $value = $metrics[$metricKey] ?? null;
            if ($value !== null && $this->breaches($value, $operator, $threshold)) {
                $breachingTargetIds[] = $targetId;
                $valueByTargetId[$targetId] = $value;
            }
        }

        if (empty($breachingTargetIds)) {
            return collect();
        }

        return Target::whereIn('id', $breachingTargetIds)
            ->get()
            ->map(function (Target $target) use ($metricKey, $operator, $threshold, $valueByTargetId): Breach {
                $observed = $valueByTargetId[$target->id];
                $description = "{$metricKey} {$observed} {$operator} {$threshold} for {$target->name}";

                return new Breach(target: $target, description: $description);
            });
    }

    private function breaches(float $observed, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>' => $observed > $threshold,
            '>=' => $observed >= $threshold,
            '<' => $observed < $threshold,
            '<=' => $observed <= $threshold,
            default => false,
        };
    }
}
