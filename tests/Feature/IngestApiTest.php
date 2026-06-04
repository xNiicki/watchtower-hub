<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\MonitoredApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'booking',
            'snapshotAt' => now()->toIso8601String(),
            'schemaVersion' => 1,
            'healthy' => true,
            'errorsLastHour' => 2,
            'queueDepth' => 5,
            'failedJobs24h' => 1,
            'mailSent24h' => 9,
            'lastDeployAt' => null,
        ], $overrides);
    }

    private function ingestToken(MonitoredApp $app): string
    {
        return $app->createToken('ingest', [TokenAbility::Ingest->value])->plainTextToken;
    }

    public function test_unauthenticated_ingest_is_rejected(): void
    {
        $this->postJson('/api/ingest/health', $this->payload())->assertUnauthorized();
    }

    public function test_token_not_bound_to_a_monitored_app_is_forbidden(): void
    {
        // A non-MonitoredApp tokenable that somehow carries the ingest ability
        // must be rejected with 403, not 500 when resolving ->slug.
        $user = User::factory()->create();
        $token = $user->createToken('rogue', [TokenAbility::Ingest->value])->plainTextToken;

        $this->postJson('/api/ingest/health', $this->payload(), [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }

    public function test_healthy_flag_round_trips_through_upsert(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $token = $this->ingestToken($app);
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/ingest/health', $this->payload(['healthy' => false]), $headers)->assertNoContent();
        $this->assertFalse($app->fresh()->health->healthy);

        // Re-ingest healthy=true to confirm the upsert UPDATE branch flips it.
        $this->postJson('/api/ingest/health', $this->payload(['healthy' => true]), $headers)->assertNoContent();
        $this->assertTrue($app->fresh()->health->healthy);
    }

    public function test_mobile_token_cannot_ingest(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone', TokenAbility::mobile())->plainTextToken;

        $this->postJson('/api/ingest/health', $this->payload(), [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }

    public function test_valid_ingest_stores_latest_health(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $token = $this->ingestToken($app);

        $this->postJson('/api/ingest/health', $this->payload(['queueDepth' => 42]), [
            'Authorization' => 'Bearer '.$token,
        ])->assertNoContent();

        $health = $app->fresh()->health;
        $this->assertNotNull($health);
        $this->assertSame(42, $health->queue_depth);
        $this->assertNotNull($health->received_at);
    }

    public function test_ingest_is_idempotent_updateorcreate(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $token = $this->ingestToken($app);
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/ingest/health', $this->payload(['queueDepth' => 1]), $headers)->assertNoContent();
        $this->postJson('/api/ingest/health', $this->payload(['queueDepth' => 2]), $headers)->assertNoContent();

        $this->assertSame(1, \App\Models\AppHealth::count());
        $this->assertSame(2, $app->fresh()->health->queue_depth);
    }

    public function test_slug_mismatch_is_rejected(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $token = $this->ingestToken($app);

        $this->postJson('/api/ingest/health', $this->payload(['slug' => 'something-else']), [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }

    public function test_unknown_schema_version_is_rejected(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $token = $this->ingestToken($app);

        $this->postJson('/api/ingest/health', $this->payload(['schemaVersion' => 999]), [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(422);
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $token = $this->ingestToken($app);

        $this->postJson('/api/ingest/health', ['slug' => 'booking'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(422);
    }
}
