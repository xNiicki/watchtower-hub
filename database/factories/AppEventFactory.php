<?php

namespace Database\Factories;

use App\Models\AppEvent;
use App\Models\MonitoredApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppEvent>
 */
class AppEventFactory extends Factory
{
    protected $model = AppEvent::class;

    public function definition(): array
    {
        return [
            'app_id' => MonitoredApp::factory(),
            'fingerprint' => fake()->unique()->sha1(),
            'type' => 'exception',
            'severity' => 'critical',
            'title' => 'TypeError',
            'message' => fake()->sentence(),
            'exception_class' => 'TypeError',
            'file' => 'app/Foo.php',
            'line' => 42,
            'trace' => "#0 app/Foo.php(42)\n#1 ...",
            'context' => ['url' => '/checkout'],
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'received_at' => now(),
        ];
    }
}
