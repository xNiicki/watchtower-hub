<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\AppHealth;
use App\Models\MonitoredApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SummaryDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_apps_expose_delivery_health(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        AppHealth::create([
            'app_id' => $app->id, 'healthy' => true, 'errors_last_hour' => 0, 'queue_depth' => 0,
            'failed_jobs_24h' => 0, 'mail_sent_24h' => 0, 'snapshot_at' => now(), 'received_at' => now(),
            'buffer_depth' => 4, 'last_ship_error' => 'POST /api/ingest/event → 404', 'degraded_since' => now()->subMinutes(6),
        ]);

        $u = User::factory()->create();
        $res = $this->getJson('/api/v1/summary', ['Authorization' => 'Bearer '.$u->createToken('m', TokenAbility::mobile())->plainTextToken])->assertOk();

        $appJson = collect($res->json('apps'))->firstWhere('slug', 'booking');
        $this->assertSame(4, $appJson['bufferDepth']);
        $this->assertSame('POST /api/ingest/event → 404', $appJson['lastShipError']);
        $this->assertTrue($appJson['deliveryDegraded']);
    }

    public function test_summary_app_without_health_is_not_degraded(): void
    {
        MonitoredApp::factory()->create(['slug' => 'newsletter']); // no health row
        $u = User::factory()->create();
        $res = $this->getJson('/api/v1/summary', ['Authorization' => 'Bearer '.$u->createToken('m', TokenAbility::mobile())->plainTextToken])->assertOk();

        $appJson = collect($res->json('apps'))->firstWhere('slug', 'newsletter');
        $this->assertSame(0, $appJson['bufferDepth']);
        $this->assertNull($appJson['lastShipError']);
        $this->assertFalse($appJson['deliveryDegraded']);
    }
}
