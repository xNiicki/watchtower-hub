<?php

namespace Tests\Feature;

use App\Enums\AlertState;
use App\Enums\AlertTier;
use App\Events\AlertFired;
use App\Models\Alert;
use App\Models\AppHealth;
use App\Models\MonitoredApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WatchAppsTest extends TestCase
{
    use RefreshDatabase;

    private function health(MonitoredApp $app, array $over = []): void
    {
        AppHealth::create(array_merge([
            'app_id' => $app->id, 'healthy' => true, 'errors_last_hour' => 0, 'queue_depth' => 0,
            'failed_jobs_24h' => 0, 'mail_sent_24h' => 0, 'snapshot_at' => now(), 'received_at' => now(),
            'buffer_depth' => 0, 'last_ship_error' => null, 'degraded_since' => null,
        ], $over));
    }

    public function test_stale_app_fires_critical_silence_alert_and_resolves_on_return(): void
    {
        Event::fake([AlertFired::class]);
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->health($app, ['received_at' => now()->subMinutes(30)]);

        $this->artisan('apps:watch')->assertOk();

        $alert = Alert::where('app_id', $app->id)->where('rule_key', 'app.silence')->firstOrFail();
        $this->assertSame(AlertTier::Critical, $alert->tier);
        $this->assertSame(AlertState::Firing->value, $alert->state->value);
        Event::assertDispatched(AlertFired::class);

        $app->health()->update(['received_at' => now()]);
        $this->artisan('apps:watch')->assertOk();
        $this->assertSame(AlertState::Resolved->value, $alert->fresh()->state->value);
    }

    public function test_degraded_past_threshold_fires_warning_and_resolves_when_drained(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->health($app, ['buffer_depth' => 3, 'last_ship_error' => 'POST /api/ingest/event → 404', 'degraded_since' => now()->subMinutes(2)]);
        $this->artisan('apps:watch')->assertOk();
        $this->assertNull(Alert::where('rule_key', 'app.delivery_degraded')->first());

        $app->health()->update(['degraded_since' => now()->subMinutes(10)]);
        $this->artisan('apps:watch')->assertOk();
        $alert = Alert::where('app_id', $app->id)->where('rule_key', 'app.delivery_degraded')->firstOrFail();
        $this->assertSame(AlertTier::Warning, $alert->tier);

        $app->health()->update(['buffer_depth' => 0, 'degraded_since' => null]);
        $this->artisan('apps:watch')->assertOk();
        $this->assertSame(AlertState::Resolved->value, $alert->fresh()->state->value);
    }

    public function test_running_twice_does_not_duplicate_alerts(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->health($app, ['received_at' => now()->subMinutes(30)]);
        $this->artisan('apps:watch')->assertOk();
        $this->artisan('apps:watch')->assertOk();
        $this->assertSame(1, Alert::where('app_id', $app->id)->where('rule_key', 'app.silence')->count());
    }
}
