<?php

namespace Database\Factories;

use App\Models\AppHealth;
use App\Models\MonitoredApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppHealth>
 */
class AppHealthFactory extends Factory
{
    protected $model = AppHealth::class;

    public function definition(): array
    {
        return [
            'app_id' => MonitoredApp::factory(),
            'healthy' => true,
            'errors_last_hour' => 0,
            'queue_depth' => 0,
            'failed_jobs_24h' => 0,
            'mail_sent_24h' => 0,
            'last_deploy_at' => null,
            'snapshot_at' => now(),
            'received_at' => now(),
        ];
    }
}
