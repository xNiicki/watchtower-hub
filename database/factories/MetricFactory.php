<?php

namespace Database\Factories;

use App\Models\Metric;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Metric>
 */
class MetricFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target_id' => Target::factory(),
            'key' => fake()->randomElement(['cpu_pct', 'mem_pct', 'disk_pct', 'latency_ms']),
            'value' => fake()->randomFloat(2, 0, 100),
            'captured_at' => now(),
        ];
    }
}
