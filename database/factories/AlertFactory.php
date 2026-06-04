<?php

namespace Database\Factories;

use App\Enums\AlertState;
use App\Enums\AlertTier;
use App\Models\Alert;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    /**
     * Default state: pending alert.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target_id' => Target::factory(),
            'rule_key' => fake()->randomElement(['down_3min', 'disk_90pct', 'backup_stale']),
            'state' => AlertState::Pending,
            'tier' => AlertTier::Critical,
            'title' => fake()->sentence(4),
            'message' => fake()->sentence(),
            'pending_since' => now()->subMinutes(2),
            'fired_at' => null,
            'resolved_at' => null,
            'acknowledged_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => AlertState::Pending,
            'pending_since' => now()->subMinutes(2),
            'fired_at' => null,
            'resolved_at' => null,
            'acknowledged_at' => null,
        ]);
    }

    public function firing(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => AlertState::Firing,
            'pending_since' => now()->subMinutes(5),
            'fired_at' => now()->subMinutes(2),
            'resolved_at' => null,
            'acknowledged_at' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => AlertState::Resolved,
            'pending_since' => now()->subMinutes(10),
            'fired_at' => now()->subMinutes(7),
            'resolved_at' => now()->subMinutes(1),
            'acknowledged_at' => null,
        ]);
    }

    public function acknowledged(): static
    {
        return $this->firing()->state(fn (array $attributes) => [
            'acknowledged_at' => now()->subMinute(),
        ]);
    }

    public function warning(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => AlertTier::Warning,
        ]);
    }
}
