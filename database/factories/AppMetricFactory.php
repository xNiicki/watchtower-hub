<?php

namespace Database\Factories;

use App\Models\AppMetric;
use App\Models\MonitoredApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppMetric> */
class AppMetricFactory extends Factory
{
    protected $model = AppMetric::class;

    public function definition(): array
    {
        return [
            'app_id' => MonitoredApp::factory(),
            'key' => 'requests',
            'value' => fake()->randomFloat(2, 0, 500),
            'bucket_at' => now()->subMinutes(fake()->unique()->numberBetween(0, 10000))->startOfMinute(),
        ];
    }
}
