<?php

namespace Tests\Feature;

use App\Models\AppEvent;
use App\Models\MonitoredApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneAppEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_events_older_than_retention(): void
    {
        config(['watchtower.apps.events.retention_days' => 120]);
        $app = MonitoredApp::factory()->create();

        $old = AppEvent::factory()->for($app, 'app')->create(['last_seen_at' => now()->subDays(130)]);
        $fresh = AppEvent::factory()->for($app, 'app')->create(['last_seen_at' => now()->subDays(10)]);

        $this->artisan('app-events:prune')->assertSuccessful();

        $this->assertDatabaseMissing('app_events', ['id' => $old->id]);
        $this->assertDatabaseHas('app_events', ['id' => $fresh->id]);
    }
}
