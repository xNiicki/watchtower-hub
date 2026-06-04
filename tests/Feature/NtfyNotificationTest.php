<?php

namespace Tests\Feature;

use App\Alerting\AlertEngine;
use App\Enums\AlertTier;
use App\Enums\TargetStatus;
use App\Events\AlertFired;
use App\Events\AlertResolved;
use App\Models\Alert;
use App\Models\Check;
use App\Models\Rule;
use App\Models\Target;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class NtfyNotificationTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t0 = CarbonImmutable::parse('2026-06-03 12:00:00');
        Carbon::setTestNow($this->t0);
        CarbonImmutable::setTestNow($this->t0);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Configure ntfy via config — avoids env file manipulation in tests.
     */
    private function configureNtfy(string $baseUrl = 'http://ntfy.test', ?string $token = 'test-token'): void
    {
        config([
            'watchtower.ntfy.base_url' => $baseUrl,
            'watchtower.ntfy.topic' => 'watchtower',
            'watchtower.ntfy.token' => $token,
        ]);
    }

    /**
     * Make a firing Critical alert pre-saved in the DB.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeFiringCriticalAlert(array $overrides = []): Alert
    {
        return Alert::factory()->firing()->create(array_merge([
            'tier' => AlertTier::Critical,
            'title' => 'test-rule: web-01',
            'message' => 'Target web-01 is down.',
            'fired_at' => $this->t0->subMinutes(5),
        ], $overrides));
    }

    /**
     * Make a resolved Critical alert pre-saved in the DB.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeResolvedCriticalAlert(array $overrides = []): Alert
    {
        return Alert::factory()->resolved()->create(array_merge([
            'tier' => AlertTier::Critical,
            'title' => 'test-rule: web-01',
            'message' => 'Target web-01 is down.',
            'fired_at' => $this->t0->subMinutes(10),
            'resolved_at' => $this->t0,
        ], $overrides));
    }

    // =========================================================================
    // Critical fired → POST with correct headers + body
    // =========================================================================

    public function test_critical_fired_alert_posts_to_ntfy_with_correct_headers_and_body(): void
    {
        $this->configureNtfy('http://ntfy.test', 'secret-token');

        Http::fake(['http://ntfy.test/watchtower' => Http::response('', 200)]);

        $alert = $this->makeFiringCriticalAlert();

        AlertFired::dispatch($alert);

        Http::assertSent(function (Request $request) use ($alert) {
            return $request->url() === 'http://ntfy.test/watchtower'
                && $request->method() === 'POST'
                && $request->header('Title')[0] === "🔴 {$alert->title}"
                && $request->header('Priority')[0] === 'urgent'
                && $request->header('Tags')[0] === 'rotating_light'
                && $request->header('Authorization')[0] === 'Bearer secret-token'
                && $request->body() === $alert->message;
        });
    }

    // =========================================================================
    // Warning fired → nothing sent (tier policy)
    // =========================================================================

    public function test_warning_fired_alert_sends_nothing(): void
    {
        $this->configureNtfy();

        Http::fake();

        $alert = Alert::factory()->firing()->create([
            'tier' => AlertTier::Warning,
            'title' => 'media-down: media-server',
            'message' => 'Media server is unreachable.',
        ]);

        AlertFired::dispatch($alert);

        Http::assertNothingSent();
    }

    // =========================================================================
    // AlertFired for alert that is already Resolved → skip + info log
    // =========================================================================

    public function test_fired_event_for_already_resolved_alert_sends_nothing_and_logs_info(): void
    {
        $this->configureNtfy();

        Http::fake();

        // Create a Resolved alert (state moved on before listener ran).
        $alert = $this->makeResolvedCriticalAlert();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'no longer Firing'));

        // Dispatch AlertFired even though the alert is already Resolved.
        AlertFired::dispatch($alert);

        Http::assertNothingSent();
    }

    // =========================================================================
    // ntfy returns 500 → Log::error, no exception escapes
    // =========================================================================

    public function test_ntfy_500_logs_error_and_does_not_propagate_exception(): void
    {
        $this->configureNtfy();

        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        $alert = $this->makeFiringCriticalAlert();

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'failed to send'));

        // Must not throw.
        AlertFired::dispatch($alert);
    }

    // =========================================================================
    // ntfy 500 during evaluate → evaluate completes without exception
    // =========================================================================

    public function test_ntfy_500_during_engine_evaluate_does_not_crash_pipeline(): void
    {
        $this->configureNtfy();

        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        Log::shouldReceive('error')->atLeast()->once();

        $rule = Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 0,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = Target::factory()->create();
        Check::factory()->for($target)->create([
            'status' => TargetStatus::Down->value,
        ]);

        $engine = app(AlertEngine::class);

        // Must not throw even though ntfy 500s.
        $summary = $engine->evaluate($this->t0);

        $this->assertSame(1, $summary->fired);
    }

    // =========================================================================
    // Resolved critical → recovery POST with default priority
    // =========================================================================

    public function test_resolved_critical_alert_sends_recovery_post_with_default_priority(): void
    {
        $this->configureNtfy('http://ntfy.test', null);

        Http::fake(['http://ntfy.test/watchtower' => Http::response('', 200)]);

        $alert = $this->makeResolvedCriticalAlert([
            'fired_at' => $this->t0->subMinutes(10),
            'resolved_at' => $this->t0,
        ]);

        AlertResolved::dispatch($alert);

        Http::assertSent(function (Request $request) use ($alert) {
            return $request->url() === 'http://ntfy.test/watchtower'
                && $request->method() === 'POST'
                && str_contains($request->header('Title')[0], "✅ Resolved: {$alert->title}")
                && $request->header('Priority')[0] === 'default'
                && $request->header('Tags')[0] === 'white_check_mark'
                && str_contains($request->body(), 'Recovered after');
        });
    }

    // =========================================================================
    // No Authorization header when token is null
    // =========================================================================

    public function test_no_authorization_header_when_token_is_null(): void
    {
        $this->configureNtfy('http://ntfy.test', null);

        Http::fake(['http://ntfy.test/watchtower' => Http::response('', 200)]);

        $alert = $this->makeFiringCriticalAlert();

        AlertFired::dispatch($alert);

        Http::assertSent(function (Request $request) {
            return empty($request->header('Authorization'));
        });
    }

    // =========================================================================
    // Warning resolved → nothing sent (recovery only for things that paged)
    // =========================================================================

    public function test_warning_resolved_alert_sends_nothing(): void
    {
        $this->configureNtfy();

        Http::fake();

        $alert = Alert::factory()->resolved()->create([
            'tier' => AlertTier::Warning,
            'title' => 'media-down: media-server',
        ]);

        AlertResolved::dispatch($alert);

        Http::assertNothingSent();
    }

    // =========================================================================
    // Unconfigured (null base_url) → nothing sent, no crash
    // =========================================================================

    public function test_unconfigured_ntfy_sends_nothing_and_does_not_crash(): void
    {
        config(['watchtower.ntfy.base_url' => null]);

        Http::fake();

        $alert = $this->makeFiringCriticalAlert();

        // Must not throw.
        AlertFired::dispatch($alert);

        Http::assertNothingSent();
    }

    public function test_unconfigured_ntfy_resolved_sends_nothing_and_does_not_crash(): void
    {
        config(['watchtower.ntfy.base_url' => null]);

        Http::fake();

        $alert = $this->makeResolvedCriticalAlert();

        AlertResolved::dispatch($alert);

        Http::assertNothingSent();
    }

    // =========================================================================
    // End-to-end: engine fires critical through real listener wiring → one POST
    // =========================================================================

    public function test_engine_fires_critical_through_real_listener_wiring_sends_exactly_one_post(): void
    {
        $this->configureNtfy('http://ntfy.test', 'e2e-token');

        Http::fake(['http://ntfy.test/watchtower' => Http::response('', 200)]);

        Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 0,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = Target::factory()->create(['name' => 'web-01']);
        Check::factory()->for($target)->create([
            'status' => TargetStatus::Down->value,
        ]);

        $engine = app(AlertEngine::class);
        $engine->evaluate($this->t0);

        Http::assertSentCount(1);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'http://ntfy.test/watchtower'
                && $request->header('Priority')[0] === 'urgent'
                && $request->header('Tags')[0] === 'rotating_light';
        });
    }
}
