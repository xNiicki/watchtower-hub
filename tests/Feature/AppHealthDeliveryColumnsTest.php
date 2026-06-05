<?php

namespace Tests\Feature;

use App\Models\AppHealth;
use App\Models\MonitoredApp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppHealthDeliveryColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_columns_persist_and_cast(): void
    {
        $app = MonitoredApp::factory()->create();
        $health = AppHealth::create([
            'app_id' => $app->id, 'healthy' => true, 'errors_last_hour' => 0,
            'queue_depth' => 0, 'failed_jobs_24h' => 0, 'mail_sent_24h' => 0,
            'snapshot_at' => now(), 'received_at' => now(),
            'buffer_depth' => 3, 'last_ship_error' => 'POST /api/ingest/event → 404',
            'degraded_since' => now()->subMinutes(7),
        ]);

        $fresh = $health->fresh();
        $this->assertSame(3, $fresh->buffer_depth);
        $this->assertSame('POST /api/ingest/event → 404', $fresh->last_ship_error);
        $this->assertInstanceOf(CarbonImmutable::class, $fresh->degraded_since);
    }
}
