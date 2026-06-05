<?php

namespace Tests\Feature;

use App\Enums\AlertState;
use App\Enums\AlertTier;
use App\Events\AlertResolved;
use App\Models\Alert;
use App\Models\AppEvent;
use App\Models\MonitoredApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SweepAppEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_quiet_firing_app_alert_is_resolved(): void
    {
        Event::fake([AlertResolved::class]);
        config(['watchtower.apps.events.quiet_after' => 60]);

        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        AppEvent::factory()->for($app, 'app')->create([
            'fingerprint' => 'fp1',
            'last_seen_at' => now()->subMinutes(120),
        ]);
        $alert = Alert::factory()->create([
            'app_id' => $app->id, 'target_id' => null,
            'rule_key' => 'app.exception:fp1',
            'state' => AlertState::Firing, 'tier' => AlertTier::Critical,
            'fired_at' => now()->subMinutes(120),
        ]);

        $this->artisan('app-events:sweep')->assertSuccessful();

        $this->assertSame(AlertState::Resolved, $alert->fresh()->state);
        Event::assertDispatched(AlertResolved::class);
    }

    public function test_recent_alert_is_not_resolved(): void
    {
        config(['watchtower.apps.events.quiet_after' => 60]);
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        AppEvent::factory()->for($app, 'app')->create([
            'fingerprint' => 'fp2',
            'last_seen_at' => now()->subMinutes(5),
        ]);
        $alert = Alert::factory()->create([
            'app_id' => $app->id, 'target_id' => null,
            'rule_key' => 'app.exception:fp2',
            'state' => AlertState::Firing, 'tier' => AlertTier::Critical,
        ]);

        $this->artisan('app-events:sweep')->assertSuccessful();

        $this->assertSame(AlertState::Firing, $alert->fresh()->state);
    }
}
