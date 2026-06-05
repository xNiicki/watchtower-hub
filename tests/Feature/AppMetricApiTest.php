<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\AppMetric;
use App\Models\MonitoredApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppMetricApiTest extends TestCase
{
    use RefreshDatabase;

    private function readHeaders(): array
    {
        $u = User::factory()->create();
        return ['Authorization' => 'Bearer '.$u->createToken('m', TokenAbility::mobile())->plainTextToken];
    }

    public function test_requires_read_token(): void
    {
        $a = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->getJson("/api/v1/apps/{$a->slug}/metrics")->assertUnauthorized();
    }

    public function test_returns_series_and_latest(): void
    {
        $a = MonitoredApp::factory()->create(['slug' => 'booking']);
        AppMetric::factory()->for($a, 'app')->create(['key' => 'requests', 'value' => 100, 'bucket_at' => now()->startOfMinute()->subMinutes(2)]);
        AppMetric::factory()->for($a, 'app')->create(['key' => 'requests', 'value' => 150, 'bucket_at' => now()->startOfMinute()]);
        $res = $this->getJson("/api/v1/apps/{$a->slug}/metrics?range=1h", $this->readHeaders())->assertOk();
        $this->assertCount(2, $res->json('series.requests'));
        $this->assertSame(150, (int) $res->json('latest.requestsPerMin'));
    }

    public function test_excludes_points_outside_range(): void
    {
        $a = MonitoredApp::factory()->create(['slug' => 'booking']);
        AppMetric::factory()->for($a, 'app')->create(['key' => 'requests', 'value' => 5, 'bucket_at' => now()->subHours(5)]);
        $res = $this->getJson("/api/v1/apps/{$a->slug}/metrics?range=1h", $this->readHeaders())->assertOk();
        $this->assertCount(0, $res->json('series.requests') ?? []);
    }

    public function test_unknown_app_404(): void
    {
        $this->getJson('/api/v1/apps/none/metrics', $this->readHeaders())->assertNotFound();
    }
}
