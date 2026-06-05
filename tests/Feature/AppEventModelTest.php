<?php

namespace Tests\Feature;

use App\Models\AppEvent;
use App\Models\MonitoredApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppEventModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_has_many_events(): void
    {
        $app = MonitoredApp::factory()->create();
        AppEvent::factory()->for($app, 'app')->count(2)->create();

        $this->assertCount(2, $app->events);
    }

    public function test_unique_per_app_and_fingerprint(): void
    {
        $app = MonitoredApp::factory()->create();
        AppEvent::factory()->for($app, 'app')->create(['fingerprint' => 'abc']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        AppEvent::factory()->for($app, 'app')->create(['fingerprint' => 'abc']);
    }

    public function test_context_is_cast_to_array(): void
    {
        $e = AppEvent::factory()->create(['context' => ['url' => '/x']]);
        $this->assertSame('/x', $e->fresh()->context['url']);
    }
}
