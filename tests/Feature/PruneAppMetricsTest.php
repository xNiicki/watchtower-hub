<?php
namespace Tests\Feature;
use App\Models\AppMetric;
use App\Models\MonitoredApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class PruneAppMetricsTest extends TestCase
{
    use RefreshDatabase;
    public function test_prunes_metrics_older_than_retention(): void
    {
        config(['watchtower.apps.metrics.retention_days' => 30]);
        $app = MonitoredApp::factory()->create();
        $old = AppMetric::factory()->for($app, 'app')->create(['bucket_at' => now()->subDays(40)]);
        $fresh = AppMetric::factory()->for($app, 'app')->create(['bucket_at' => now()->subDays(5)]);

        $this->artisan('app-metrics:prune')->assertSuccessful();

        $this->assertDatabaseMissing('app_metrics', ['id' => $old->id]);
        $this->assertDatabaseHas('app_metrics', ['id' => $fresh->id]);
    }
}
