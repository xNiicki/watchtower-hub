<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\MonitoredApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertAppRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_can_belong_to_an_app(): void
    {
        $app = MonitoredApp::factory()->create();
        $alert = Alert::factory()->create(['app_id' => $app->id, 'target_id' => null]);

        $this->assertSame($app->id, $alert->fresh()->app->id);
    }
}
