<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\AppHealth;
use App\Models\MonitoredApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SummaryAppsTest extends TestCase
{
    use RefreshDatabase;

    private function readHeaders(): array
    {
        $user = User::factory()->create();

        return ['Authorization' => 'Bearer '.$user->createToken('m', TokenAbility::mobile())->plainTextToken];
    }

    public function test_fresh_app_appears_healthy_and_not_stale(): void
    {
        $app = MonitoredApp::factory()->create(['name' => 'Booking', 'slug' => 'booking']);
        AppHealth::factory()->for($app, 'app')->create([
            'healthy' => true,
            'queue_depth' => 4,
            'received_at' => now()->subMinute(),
        ]);

        $response = $this->getJson('/api/v1/summary', $this->readHeaders())->assertOk();

        $apps = $response->json('apps');
        $this->assertCount(1, $apps);
        $this->assertSame('Booking', $apps[0]['name']);
        $this->assertTrue($apps[0]['healthy']);
        $this->assertFalse($apps[0]['stale']);
        $this->assertSame(4, $apps[0]['queueDepth']);
        $this->assertNotNull($apps[0]['lastSeenAt']);
    }

    public function test_app_past_stale_threshold_is_stale_and_unhealthy(): void
    {
        config(['watchtower.apps.stale_after' => 15]);
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        AppHealth::factory()->for($app, 'app')->create([
            'healthy' => true,
            'received_at' => now()->subMinutes(30),
        ]);

        $apps = $this->getJson('/api/v1/summary', $this->readHeaders())->assertOk()->json('apps');

        $this->assertTrue($apps[0]['stale']);
        $this->assertFalse($apps[0]['healthy']);
    }

    public function test_app_without_a_snapshot_is_stale(): void
    {
        MonitoredApp::factory()->create(['slug' => 'booking']);

        $apps = $this->getJson('/api/v1/summary', $this->readHeaders())->assertOk()->json('apps');

        $this->assertTrue($apps[0]['stale']);
        $this->assertFalse($apps[0]['healthy']);
        $this->assertNull($apps[0]['lastSeenAt']);
    }
}
