<?php

namespace Tests\Feature;

use App\Alerting\AppEventRecorder;
use App\Enums\AlertState;
use App\Enums\AlertTier;
use App\Events\AlertFired;
use App\Models\Alert;
use App\Models\AppEvent;
use App\Models\MonitoredApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AppEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $o = []): array
    {
        return array_merge([
            'fingerprint' => 'fp1', 'type' => 'exception', 'title' => 'TypeError',
            'message' => 'boom', 'exceptionClass' => 'TypeError', 'file' => 'app/Foo.php',
            'line' => 10, 'trace' => '#0 ...', 'context' => ['url' => '/x'], 'occurrences' => 1,
        ], $o);
    }

    public function test_first_critical_event_creates_group_and_fires_alert(): void
    {
        Event::fake([AlertFired::class]);
        $app = MonitoredApp::factory()->create(['name' => 'Booking', 'slug' => 'booking']);

        app(AppEventRecorder::class)->record($app, $this->payload(['occurrences' => 3]));

        $event = AppEvent::where('app_id', $app->id)->where('fingerprint', 'fp1')->firstOrFail();
        $this->assertSame(3, $event->occurrences);
        $this->assertSame('critical', $event->severity);

        $alert = Alert::where('app_id', $app->id)->firstOrFail();
        $this->assertSame(AlertState::Firing, $alert->state);
        $this->assertSame(AlertTier::Critical, $alert->tier);
        $this->assertStringContainsString('Booking', $alert->title);
        Event::assertDispatched(AlertFired::class);
    }

    public function test_recurrence_increments_and_does_not_refire_within_cooldown(): void
    {
        Event::fake([AlertFired::class]);
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);

        app(AppEventRecorder::class)->record($app, $this->payload(['occurrences' => 1]));
        app(AppEventRecorder::class)->record($app, $this->payload(['occurrences' => 2]));

        $this->assertSame(3, AppEvent::where('app_id', $app->id)->firstOrFail()->occurrences);
        $this->assertSame(1, Alert::where('app_id', $app->id)->count());
        Event::assertDispatchedTimes(AlertFired::class, 1);
    }

    public function test_warning_event_records_without_alert(): void
    {
        Event::fake([AlertFired::class]);
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);

        app(AppEventRecorder::class)->record($app, $this->payload([
            'type' => 'failed_scheduled_task', 'fingerprint' => 'fp2',
        ]));

        $this->assertSame('warning', AppEvent::where('fingerprint', 'fp2')->firstOrFail()->severity);
        $this->assertSame(0, Alert::count());
        Event::assertNotDispatched(AlertFired::class);
    }

    public function test_new_alert_after_resolution_pages_even_within_cooldown_window(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\AlertFired::class]);
        $app = \App\Models\MonitoredApp::factory()->create(['slug' => 'booking']);

        // First occurrence creates + pages.
        app(\App\Alerting\AppEventRecorder::class)->record($app, $this->payload(['fingerprint' => 'fpx']));

        // Resolve the alert (simulating the quiet-after sweep), leaving the cooldown cache key live.
        \App\Models\Alert::where('app_id', $app->id)->update(['state' => \App\Enums\AlertState::Resolved->value]);

        // A new occurrence of the SAME fingerprint must create a fresh firing alert AND page again.
        app(\App\Alerting\AppEventRecorder::class)->record($app, $this->payload(['fingerprint' => 'fpx']));

        $this->assertSame(2, \App\Models\Alert::where('app_id', $app->id)->count());
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\AlertFired::class, 2);
    }
}
