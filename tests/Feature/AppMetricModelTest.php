<?php

namespace Tests\Feature;

use App\Models\AppMetric;
use App\Models\MonitoredApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppMetricModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_has_many_metrics(): void
    {
        $app = MonitoredApp::factory()->create();
        AppMetric::factory()->for($app, 'app')->count(3)->create();
        $this->assertCount(3, $app->metrics);
    }

    public function test_unique_per_app_key_bucket(): void
    {
        $app = MonitoredApp::factory()->create();
        $at = now()->startOfMinute();
        AppMetric::factory()->for($app, 'app')->create(['key' => 'requests', 'bucket_at' => $at]);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        AppMetric::factory()->for($app, 'app')->create(['key' => 'requests', 'bucket_at' => $at]);
    }
}
