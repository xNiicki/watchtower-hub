<?php

namespace Database\Factories;

use App\Models\MonitoredApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoredApp>
 */
class MonitoredAppFactory extends Factory
{
    protected $model = MonitoredApp::class;

    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'name' => \Illuminate\Support\Str::title(str_replace('-', ' ', $slug)),
            'slug' => $slug,
        ];
    }
}
