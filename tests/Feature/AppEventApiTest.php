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
}
