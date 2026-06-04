<?php

namespace Database\Factories;

use App\Enums\AlertTier;
use App\Models\Rule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rule>
 */
class RuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'condition_type' => 'target_down',
            'params' => [],
            'duration_seconds' => 0,
            'tier' => AlertTier::Critical->value,
            'enabled' => true,
        ];
    }

    public function targetDown(): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_type' => 'target_down',
            'params' => [],
        ]);
    }

    public function metricThreshold(string $metric, string $operator, float $value, int $stalenessMinutes = 15): static
    {
        return $this->state(fn (array $attributes) => [
            'condition_type' => 'metric_threshold',
            'params' => [
                'metric' => $metric,
                'operator' => $operator,
                'value' => $value,
                'staleness_minutes' => $stalenessMinutes,
            ],
        ]);
    }

    public function warning(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => AlertTier::Warning->value,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }
}
