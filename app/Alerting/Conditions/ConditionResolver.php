<?php

namespace App\Alerting\Conditions;

use Illuminate\Support\Facades\Log;

class ConditionResolver
{
    /**
     * @var array<string, class-string<Condition>>
     */
    private const array MAP = [
        'target_down' => TargetDownCondition::class,
        'metric_threshold' => MetricThresholdCondition::class,
    ];

    public function resolve(string $conditionType): ?Condition
    {
        $class = self::MAP[$conditionType] ?? null;

        if ($class === null) {
            Log::warning("AlertEngine: unknown condition_type [{$conditionType}], skipping rule.");

            return null;
        }

        return app($class);
    }
}
