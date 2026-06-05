<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\AppHealth;
use App\Models\MonitoredApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IngestHealthDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function ship(MonitoredApp $app, array $over = []): TestResponse
    {
        $token = $app->createToken('i', [TokenAbility::Ingest->value])->plainTextToken;
        $payload = array_merge([
            'slug' => $app->slug, 'snapshotAt' => now()->toIso8601String(), 'schemaVersion' => 2,
            'healthy' => true, 'errorsLastHour' => 0, 'queueDepth' => 0,
            'failedJobs24h' => 0, 'mailSent24h' => 0, 'lastDeployAt' => null,
            'bufferDepth' => 0, 'lastShipError' => null,
        ], $over);

        return $this->postJson('/api/ingest/health', $payload, ['Authorization' => 'Bearer '.$token]);
    }

    public function test_v1_payload_without_delivery_fields_still_accepted(): void
    {
        $app = MonitoredApp::factory()->create();
        $token = $app->createToken('i', [TokenAbility::Ingest->value])->plainTextToken;
        $v1 = ['slug' => $app->slug, 'snapshotAt' => now()->toIso8601String(), 'schemaVersion' => 1,
            'healthy' => true, 'errorsLastHour' => 0, 'queueDepth' => 0, 'failedJobs24h' => 0, 'mailSent24h' => 0, 'lastDeployAt' => null];
        $this->postJson('/api/ingest/health', $v1, ['Authorization' => 'Bearer '.$token])->assertNoContent();
        $this->assertSame(0, AppHealth::where('app_id', $app->id)->first()->buffer_depth);
    }

    public function test_degraded_since_sets_on_first_backlog_and_clears_when_drained(): void
    {
        $app = MonitoredApp::factory()->create();

        $this->ship($app, ['bufferDepth' => 0])->assertNoContent();
        $this->assertNull(AppHealth::where('app_id', $app->id)->first()->degraded_since);

        $this->ship($app, ['bufferDepth' => 2, 'lastShipError' => 'POST /api/ingest/event → 404'])->assertNoContent();
        $first = AppHealth::where('app_id', $app->id)->first();
        $this->assertNotNull($first->degraded_since);
        $this->assertSame('POST /api/ingest/event → 404', $first->last_ship_error);

        $this->ship($app, ['bufferDepth' => 5, 'lastShipError' => 'POST /api/ingest/event → 404'])->assertNoContent();
        $this->assertEquals($first->degraded_since, AppHealth::where('app_id', $app->id)->first()->degraded_since);

        $this->ship($app, ['bufferDepth' => 0])->assertNoContent();
        $this->assertNull(AppHealth::where('app_id', $app->id)->first()->degraded_since);
    }
}
