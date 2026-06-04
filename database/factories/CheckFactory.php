<?php

namespace Database\Factories;

use App\Enums\TargetStatus;
use App\Models\Check;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Check>
 */
class CheckFactory extends Factory
{
    /**
     * Default state: unknown status, no streak.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target_id' => Target::factory(),
            'status' => TargetStatus::Unknown,
            'latency_ms' => null,
            'fail_streak' => 0,
            'last_ok_at' => null,
            'last_checked_at' => now(),
        ];
    }

    public function up(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TargetStatus::Up,
            'latency_ms' => fake()->numberBetween(1, 200),
            'fail_streak' => 0,
            'last_ok_at' => now(),
        ]);
    }

    public function down(int $streak = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TargetStatus::Down,
            'latency_ms' => null,
            'fail_streak' => $streak,
            'last_ok_at' => now()->subMinutes($streak * 2),
        ]);
    }

    public function unknown(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TargetStatus::Unknown,
            'latency_ms' => null,
            'fail_streak' => 0,
            'last_ok_at' => null,
            'last_checked_at' => null,
        ]);
    }
}
