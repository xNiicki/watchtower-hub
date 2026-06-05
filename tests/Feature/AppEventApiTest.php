<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\AppEvent;
use App\Models\MonitoredApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppEventApiTest extends TestCase
{
    use RefreshDatabase;

    private function readHeaders(): array
    {
        $u = User::factory()->create();

        return ['Authorization' => 'Bearer '.$u->createToken('m', TokenAbility::mobile())->plainTextToken];
    }

    public function test_requires_read_token(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->getJson("/api/v1/apps/{$app->slug}/events")->assertUnauthorized();
    }

    public function test_lists_events_newest_first_with_expected_shape(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        AppEvent::factory()->for($app, 'app')->create(['title' => 'Old', 'last_seen_at' => now()->subHour()]);
        AppEvent::factory()->for($app, 'app')->create(['title' => 'New', 'occurrences' => 9, 'last_seen_at' => now()]);

        $res = $this->getJson("/api/v1/apps/{$app->slug}/events", $this->readHeaders())->assertOk();

        $data = $res->json();
        $this->assertSame('New', $data[0]['title']);
        $this->assertSame(9, $data[0]['occurrences']);
        $this->assertArrayHasKey('lastSeenAt', $data[0]);
        $this->assertArrayHasKey('severity', $data[0]);
        $this->assertArrayNotHasKey('trace', $data[0]);
    }

    public function test_search_filters_message(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        AppEvent::factory()->for($app, 'app')->create(['message' => 'disk FULL on root']);
        AppEvent::factory()->for($app, 'app')->create(['message' => 'routine cron ok']);

        $data = $this->getJson("/api/v1/apps/{$app->slug}/events?search=full", $this->readHeaders())->assertOk()->json();

        $this->assertCount(1, $data);
        $this->assertStringContainsStringIgnoringCase('disk', $data[0]['message']);
    }

    public function test_unknown_app_is_404(): void
    {
        $this->getJson('/api/v1/apps/nope/events', $this->readHeaders())->assertNotFound();
    }

    public function test_show_returns_full_event_with_trace_and_context(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $event = AppEvent::factory()->for($app, 'app')->create([
            'type' => 'exception',
            'severity' => 'critical',
            'title' => 'TypeError',
            'message' => 'Cannot read property x',
            'exception_class' => 'TypeError',
            'file' => 'app/Services/Foo.php',
            'line' => 42,
            'trace' => '#0 app/Services/Foo.php(42): Foo->load()',
            'context' => ['queue' => 'default', 'connection' => 'redis'],
        ]);

        $data = $this->getJson("/api/v1/apps/{$app->slug}/events/{$event->id}", $this->readHeaders())
            ->assertOk()->json();

        $this->assertSame((string) $event->id, $data['id']);
        $this->assertSame('app/Services/Foo.php', $data['file']);
        $this->assertSame(42, $data['line']);
        $this->assertSame('TypeError', $data['exceptionClass']);
        $this->assertStringContainsString('Foo->load()', $data['trace']);
        $this->assertSame(['queue' => 'default', 'connection' => 'redis'], $data['context']);
    }

    public function test_show_requires_read_token(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $event = AppEvent::factory()->for($app, 'app')->create();
        $this->getJson("/api/v1/apps/{$app->slug}/events/{$event->id}")->assertUnauthorized();
    }

    public function test_show_unknown_id_is_404(): void
    {
        $app = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->getJson("/api/v1/apps/{$app->slug}/events/999999", $this->readHeaders())->assertNotFound();
    }

    public function test_show_event_from_another_app_is_404(): void
    {
        $a = MonitoredApp::factory()->create(['slug' => 'booking']);
        $b = MonitoredApp::factory()->create(['slug' => 'other']);
        $event = AppEvent::factory()->for($b, 'app')->create();
        $this->getJson("/api/v1/apps/{$a->slug}/events/{$event->id}", $this->readHeaders())->assertNotFound();
    }
}
