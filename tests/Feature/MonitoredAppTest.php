<?php

namespace Tests\Feature;

use App\Models\AppHealth;
use App\Models\MonitoredApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoredAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_has_one_health_snapshot(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        AppHealth::factory()->for($app, 'app')->create(['queue_depth' => 7]);

        $this->assertSame('booking', $app->slug);
        $this->assertSame(7, $app->health->queue_depth);
    }

    public function test_app_can_mint_a_sanctum_token(): void
    {
        $app = MonitoredApp::factory()->create();
        $token = $app->createToken('ingest', ['ingest']);

        $this->assertTrue($token->accessToken->can('ingest'));
        $this->assertSame($app->id, $token->accessToken->tokenable->id);
    }
}
