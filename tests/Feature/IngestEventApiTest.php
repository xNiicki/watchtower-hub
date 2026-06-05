<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\AppEvent;
use App\Models\MonitoredApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestEventApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $o = []): array
    {
        return array_merge([
            'slug' => 'booking', 'schemaVersion' => 1, 'fingerprint' => 'fp1',
            'type' => 'exception', 'title' => 'TypeError', 'message' => 'boom',
            'exceptionClass' => 'TypeError', 'file' => 'app/Foo.php', 'line' => 10,
            'trace' => '#0 ...', 'context' => ['url' => '/x'], 'occurrences' => 2,
            'occurredAt' => now()->toIso8601String(),
        ], $o);
    }

    private function token(MonitoredApp $app): string
    {
        return $app->createToken('i', [TokenAbility::Ingest->value])->plainTextToken;
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->postJson('/api/ingest/event', $this->payload())->assertUnauthorized();
    }

    public function test_mobile_token_cannot_ingest_events(): void
    {
        $u = User::factory()->create();
        $t = $u->createToken('p', TokenAbility::mobile())->plainTextToken;
        $this->postJson('/api/ingest/event', $this->payload(), ['Authorization' => 'Bearer '.$t])->assertForbidden();
    }

    public function test_valid_event_is_stored(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->postJson('/api/ingest/event', $this->payload(['occurrences' => 5]), [
            'Authorization' => 'Bearer '.$this->token($app),
        ])->assertNoContent();

        $this->assertSame(5, AppEvent::where('app_id', $app->id)->firstOrFail()->occurrences);
    }

    public function test_first_and_last_seen_use_occurred_at(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $occurredAt = now()->subMinutes(30)->startOfSecond();

        $this->postJson('/api/ingest/event', $this->payload(['occurredAt' => $occurredAt->toIso8601String()]), [
            'Authorization' => 'Bearer '.$this->token($app),
        ])->assertNoContent();

        $event = AppEvent::where('app_id', $app->id)->firstOrFail();
        $this->assertTrue($event->first_seen_at->equalTo($occurredAt), 'first_seen_at should equal occurredAt');
        $this->assertTrue($event->last_seen_at->equalTo($occurredAt), 'last_seen_at should equal occurredAt');
    }

    public function test_out_of_order_event_does_not_move_last_seen_backward(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $headers = ['Authorization' => 'Bearer '.$this->token($app)];
        $newer = now()->subMinutes(5)->startOfSecond();
        $older = now()->subMinutes(30)->startOfSecond();

        $this->postJson('/api/ingest/event', $this->payload(['occurredAt' => $newer->toIso8601String()]), $headers)->assertNoContent();
        $this->postJson('/api/ingest/event', $this->payload(['occurredAt' => $older->toIso8601String()]), $headers)->assertNoContent();

        $event = AppEvent::where('app_id', $app->id)->firstOrFail();
        $this->assertTrue($event->last_seen_at->equalTo($newer), 'last_seen_at must not move backward for out-of-order arrivals');
    }

    public function test_slug_mismatch_is_forbidden(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->postJson('/api/ingest/event', $this->payload(['slug' => 'other']), [
            'Authorization' => 'Bearer '.$this->token($app),
        ])->assertForbidden();
    }

    public function test_unknown_schema_version_is_422(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->postJson('/api/ingest/event', $this->payload(['schemaVersion' => 99]), [
            'Authorization' => 'Bearer '.$this->token($app),
        ])->assertStatus(422);
    }

    public function test_invalid_type_is_422(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->postJson('/api/ingest/event', $this->payload(['type' => 'nonsense']), [
            'Authorization' => 'Bearer '.$this->token($app),
        ])->assertStatus(422);
    }
}
