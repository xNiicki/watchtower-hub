<?php

namespace Tests\Feature;

use App\Alerting\AlertEngine;
use App\Enums\AlertState;
use App\Enums\AlertTier;
use App\Enums\TargetStatus;
use App\Events\AlertFired;
use App\Events\AlertResolved;
use App\Models\Alert;
use App\Models\Check;
use App\Models\Metric;
use App\Models\Rule;
use App\Models\Target;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AlertEngineTest extends TestCase
{
    use RefreshDatabase;

    private AlertEngine $engine;

    private CarbonImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(AlertEngine::class);
        $this->t0 = CarbonImmutable::parse('2026-06-03 12:00:00');
        Carbon::setTestNow($this->t0);
    }

    // -------------------------------------------------------------------------
    // Helper: create a target with a Check in a given status.
    // -------------------------------------------------------------------------

    private function targetWithCheck(string $name, TargetStatus $status, bool $enabled = true): Target
    {
        $target = Target::factory()->create(['name' => $name, 'enabled' => $enabled]);
        Check::factory()->for($target)->create(['status' => $status->value]);

        return $target;
    }

    // =========================================================================
    // TargetDownCondition — basic state machine
    // =========================================================================

    public function test_down_target_creates_pending_alert_with_correct_tier_and_title(): void
    {
        Event::fake();

        $rule = Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 180,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = $this->targetWithCheck('web-01', TargetStatus::Down);

        $summary = $this->engine->evaluate($this->t0);

        $this->assertSame(1, $summary->pendingCreated);
        $this->assertSame(0, $summary->fired);
        $this->assertSame(0, $summary->resolved);

        $alert = Alert::where('rule_key', 'infra-down')->where('target_id', $target->id)->first();
        $this->assertNotNull($alert);
        $this->assertSame(AlertState::Pending, $alert->state);
        $this->assertSame(AlertTier::Critical, $alert->tier);
        $this->assertSame('infra-down: web-01', $alert->title);
        $this->assertEquals($this->t0->toDateTimeString(), $alert->pending_since->toDateTimeString());
        $this->assertNull($alert->fired_at);

        Event::assertNotDispatched(AlertFired::class);
        Event::assertNotDispatched(AlertResolved::class);
    }

    public function test_still_down_after_duration_transitions_to_firing_and_dispatches_event(): void
    {
        Event::fake();

        $rule = Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 180,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = $this->targetWithCheck('web-01', TargetStatus::Down);

        // First evaluate: pending.
        $this->engine->evaluate($this->t0);
        Event::assertNotDispatched(AlertFired::class);

        // Advance time past the duration.
        $t1 = $this->t0->addSeconds(181);
        Carbon::setTestNow($t1);

        // Second evaluate: should fire.
        $summary = $this->engine->evaluate($t1);

        $this->assertSame(0, $summary->pendingCreated);
        $this->assertSame(1, $summary->fired);
        $this->assertSame(0, $summary->resolved);

        $alert = Alert::where('rule_key', 'infra-down')->where('target_id', $target->id)->first();
        $this->assertSame(AlertState::Firing, $alert->state);
        $this->assertNotNull($alert->fired_at);
        $this->assertEquals($t1->toDateTimeString(), $alert->fired_at->toDateTimeString());

        Event::assertDispatched(AlertFired::class, fn ($e) => $e->alert->id === $alert->id);
    }

    public function test_re_evaluate_after_firing_does_not_create_duplicate_alerts_or_events(): void
    {
        Event::fake();

        $rule = Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 180,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = $this->targetWithCheck('web-01', TargetStatus::Down);

        $this->engine->evaluate($this->t0);
        $t1 = $this->t0->addSeconds(181);
        $this->engine->evaluate($t1);

        // Third evaluate: target still down, alert already firing.
        $t2 = $t1->addSeconds(30);
        Carbon::setTestNow($t2);
        $summary = $this->engine->evaluate($t2);

        $this->assertSame(0, $summary->pendingCreated);
        $this->assertSame(0, $summary->fired);
        $this->assertSame(0, $summary->resolved);

        $this->assertSame(1, Alert::where('rule_key', 'infra-down')->count());
        Event::assertDispatchedTimes(AlertFired::class, 1);
    }

    public function test_flap_before_duration_resolves_silently_without_any_event(): void
    {
        // THE DEBOUNCE PIN: target goes down, alert goes pending, target recovers
        // before duration expires → alert resolves with fired_at = null and
        // ZERO events are ever dispatched.
        Event::fake();

        $rule = Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 180,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = $this->targetWithCheck('web-01', TargetStatus::Down);

        // First evaluate: pending.
        $this->engine->evaluate($this->t0);

        // Target recovers before 180 s elapse.
        $t1 = $this->t0->addSeconds(60);
        Carbon::setTestNow($t1);
        $check = $target->check;
        $check->status = TargetStatus::Up;
        $check->save();

        $summary = $this->engine->evaluate($t1);

        $this->assertSame(0, $summary->pendingCreated);
        $this->assertSame(0, $summary->fired);
        $this->assertSame(1, $summary->resolved);

        $alert = Alert::where('rule_key', 'infra-down')->where('target_id', $target->id)->first();
        $this->assertSame(AlertState::Resolved, $alert->state);
        $this->assertNull($alert->fired_at, 'Alert was never fired — debounce did its job');
        $this->assertEquals($t1->toDateTimeString(), $alert->resolved_at->toDateTimeString());

        // No events ever dispatched — the whole point of debounce.
        Event::assertNotDispatched(AlertFired::class);
        Event::assertNotDispatched(AlertResolved::class);
    }

    public function test_recovery_after_firing_resolves_and_dispatches_alert_resolved(): void
    {
        Event::fake();

        $rule = Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 180,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = $this->targetWithCheck('web-01', TargetStatus::Down);

        $this->engine->evaluate($this->t0);

        // Advance past duration → fires.
        $t1 = $this->t0->addSeconds(181);
        Carbon::setTestNow($t1);
        $this->engine->evaluate($t1);
        Event::assertDispatched(AlertFired::class);

        // Target recovers.
        $t2 = $t1->addMinutes(5);
        Carbon::setTestNow($t2);
        $check = $target->check;
        $check->status = TargetStatus::Up;
        $check->save();

        $summary = $this->engine->evaluate($t2);

        $this->assertSame(0, $summary->pendingCreated);
        $this->assertSame(0, $summary->fired);
        $this->assertSame(1, $summary->resolved);

        $alert = Alert::where('rule_key', 'infra-down')->where('target_id', $target->id)->first();
        $this->assertSame(AlertState::Resolved, $alert->state);
        $this->assertNotNull($alert->fired_at);
        $this->assertEquals($t2->toDateTimeString(), $alert->resolved_at->toDateTimeString());

        Event::assertDispatchedTimes(AlertResolved::class, 1);
        Event::assertDispatched(AlertResolved::class, fn ($e) => $e->alert->id === $alert->id);
    }

    // =========================================================================
    // exclude_targets / include_targets routing
    // =========================================================================

    public function test_exclude_targets_prevents_infra_down_alert_for_excluded_target(): void
    {
        Event::fake();

        // infra-down excludes media-server.
        Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'params' => ['exclude_targets' => ['media-server', 'downloader']],
            'duration_seconds' => 180,
            'tier' => AlertTier::Critical->value,
        ]);

        // media-down includes only media-server and downloader.
        Rule::factory()->targetDown()->warning()->create([
            'key' => 'media-down',
            'params' => ['include_targets' => ['media-server', 'downloader']],
            'duration_seconds' => 300,
        ]);

        $mediaServer = $this->targetWithCheck('media-server', TargetStatus::Down);

        $this->engine->evaluate($this->t0);

        // No infra-down alert for media-server.
        $this->assertDatabaseMissing('alerts', [
            'rule_key' => 'infra-down',
            'target_id' => $mediaServer->id,
        ]);

        // media-down warning alert for media-server.
        $alert = Alert::where('rule_key', 'media-down')->where('target_id', $mediaServer->id)->first();
        $this->assertNotNull($alert);
        $this->assertSame(AlertTier::Warning, $alert->tier);
    }

    // =========================================================================
    // MetricThresholdCondition
    // =========================================================================

    public function test_metric_breach_creates_pending_with_observed_value_in_description(): void
    {
        Event::fake();

        $rule = Rule::factory()->metricThreshold('disk_pct', '>=', 90)->create([
            'key' => 'disk-high',
            'duration_seconds' => 300,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = Target::factory()->create();
        Metric::factory()->for($target)->create([
            'key' => 'disk_pct',
            'value' => 91.4,
            'captured_at' => $this->t0,
        ]);

        $this->engine->evaluate($this->t0);

        $alert = Alert::where('rule_key', 'disk-high')->where('target_id', $target->id)->first();
        $this->assertNotNull($alert);
        $this->assertSame(AlertState::Pending, $alert->state);
        $this->assertStringContainsString('91.4', $alert->message);
        $this->assertStringContainsString('>=', $alert->message);
        $this->assertStringContainsString('90', $alert->message);
    }

    public function test_metric_below_threshold_creates_no_alert(): void
    {
        Event::fake();

        Rule::factory()->metricThreshold('disk_pct', '>=', 90)->create([
            'key' => 'disk-high',
            'duration_seconds' => 300,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = Target::factory()->create();
        Metric::factory()->for($target)->create([
            'key' => 'disk_pct',
            'value' => 80.0,
            'captured_at' => $this->t0,
        ]);

        $summary = $this->engine->evaluate($this->t0);

        $this->assertSame(0, $summary->pendingCreated);
        $this->assertDatabaseMissing('alerts', ['rule_key' => 'disk-high', 'target_id' => $target->id]);
    }

    public function test_stale_metric_is_ignored_and_existing_firing_alert_resolves(): void
    {
        Event::fake();

        $rule = Rule::factory()->metricThreshold('disk_pct', '>=', 90, 15)->create([
            'key' => 'disk-high',
            'duration_seconds' => 300,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = Target::factory()->create();

        // Metric is 20 minutes old — outside the 15-minute staleness window.
        Metric::factory()->for($target)->create([
            'key' => 'disk_pct',
            'value' => 95.0,
            'captured_at' => $this->t0->subMinutes(20),
        ]);

        // Pre-existing firing alert.
        Alert::factory()->firing()->for($target)->create([
            'rule_key' => 'disk-high',
            'fired_at' => $this->t0->subMinutes(10),
        ]);

        $summary = $this->engine->evaluate($this->t0);

        // Stale metric → no breach → existing alert should resolve.
        $this->assertSame(0, $summary->pendingCreated);
        $this->assertSame(0, $summary->fired);
        $this->assertSame(1, $summary->resolved);

        $alert = Alert::where('rule_key', 'disk-high')->where('target_id', $target->id)->first();
        $this->assertSame(AlertState::Resolved, $alert->state);

        Event::assertDispatched(AlertResolved::class, fn ($e) => $e->alert->id === $alert->id);
    }

    // =========================================================================
    // duration_seconds = 0: fires on first evaluate
    // =========================================================================

    public function test_duration_zero_fires_immediately_on_first_evaluate(): void
    {
        Event::fake();

        $rule = Rule::factory()->metricThreshold('backup_age_hours', '>', 26)->create([
            'key' => 'backup-stale',
            'duration_seconds' => 0,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = Target::factory()->create();
        Metric::factory()->for($target)->create([
            'key' => 'backup_age_hours',
            'value' => 28.0,
            'captured_at' => $this->t0,
        ]);

        $summary = $this->engine->evaluate($this->t0);

        // One evaluate should both create and immediately fire the alert.
        $this->assertSame(1, $summary->pendingCreated);
        $this->assertSame(1, $summary->fired);

        $alert = Alert::where('rule_key', 'backup-stale')->where('target_id', $target->id)->first();
        $this->assertSame(AlertState::Firing, $alert->state);
        $this->assertNotNull($alert->fired_at);

        Event::assertDispatched(AlertFired::class, fn ($e) => $e->alert->id === $alert->id);
    }

    // =========================================================================
    // Acknowledged alert: orthogonal to state transitions
    // =========================================================================

    public function test_acknowledged_firing_alert_still_resolves_on_recovery(): void
    {
        Event::fake();

        Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 180,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = $this->targetWithCheck('web-01', TargetStatus::Down);

        // Pre-existing acknowledged firing alert.
        Alert::factory()->acknowledged()->for($target)->create([
            'rule_key' => 'infra-down',
            'pending_since' => $this->t0->subMinutes(10),
            'fired_at' => $this->t0->subMinutes(7),
        ]);

        // Target recovers.
        $check = $target->check;
        $check->status = TargetStatus::Up;
        $check->save();

        $summary = $this->engine->evaluate($this->t0);

        $this->assertSame(1, $summary->resolved);

        $alert = Alert::where('rule_key', 'infra-down')->where('target_id', $target->id)->first();
        $this->assertSame(AlertState::Resolved, $alert->state);

        Event::assertDispatched(AlertResolved::class);
    }

    // =========================================================================
    // Unknown condition_type: warning logged, no crash
    // =========================================================================

    public function test_unknown_condition_type_logs_warning_and_does_not_crash(): void
    {
        Event::fake();

        Rule::factory()->create([
            'key' => 'weird-rule',
            'condition_type' => 'totally_unknown',
            'params' => [],
            'duration_seconds' => 0,
            'tier' => AlertTier::Critical->value,
        ]);

        // Should not throw.
        $summary = $this->engine->evaluate($this->t0);

        $this->assertSame(0, $summary->pendingCreated);
        $this->assertSame(0, $summary->fired);
        $this->assertSame(0, $summary->resolved);
    }

    // =========================================================================
    // Disabled rule / disabled target: ignored
    // =========================================================================

    public function test_disabled_rule_is_not_evaluated(): void
    {
        Event::fake();

        Rule::factory()->targetDown()->disabled()->create([
            'key' => 'infra-down',
            'duration_seconds' => 0,
            'tier' => AlertTier::Critical->value,
        ]);

        $this->targetWithCheck('web-01', TargetStatus::Down);

        $summary = $this->engine->evaluate($this->t0);

        $this->assertSame(0, $summary->pendingCreated);
        $this->assertDatabaseMissing('alerts', ['rule_key' => 'infra-down']);
    }

    public function test_disabled_target_is_not_included_in_target_down_breach(): void
    {
        Event::fake();

        Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 0,
            'tier' => AlertTier::Critical->value,
        ]);

        // Disabled target — should be ignored by TargetDownCondition.
        $target = $this->targetWithCheck('web-01', TargetStatus::Down, enabled: false);

        $summary = $this->engine->evaluate($this->t0);

        $this->assertSame(0, $summary->pendingCreated);
        $this->assertDatabaseMissing('alerts', ['target_id' => $target->id]);
    }

    public function test_disabled_target_is_not_included_in_metric_threshold_breach(): void
    {
        Event::fake();

        Rule::factory()->metricThreshold('disk_pct', '>=', 90)->create([
            'key' => 'disk-high',
            'duration_seconds' => 0,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = Target::factory()->disabled()->create();
        Metric::factory()->for($target)->create([
            'key' => 'disk_pct',
            'value' => 95.0,
            'captured_at' => $this->t0,
        ]);

        $summary = $this->engine->evaluate($this->t0);

        $this->assertSame(0, $summary->pendingCreated);
        $this->assertDatabaseMissing('alerts', ['target_id' => $target->id]);
    }

    // =========================================================================
    // Orphaned-alert sweep: disabled / deleted rules
    // =========================================================================

    public function test_disabled_rule_mid_pending_resolves_alert_silently(): void
    {
        // Target goes down → pending alert is created.
        // Rule is then disabled → on next evaluate, the orphaned pending alert is
        // resolved silently (no events because fired_at is null).
        Event::fake();

        $rule = Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 180,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = $this->targetWithCheck('web-01', TargetStatus::Down);

        // First evaluate: creates pending alert.
        $this->engine->evaluate($this->t0);
        $this->assertSame(1, Alert::where('rule_key', 'infra-down')->count());

        // Disable the rule (simulating operator disabling it mid-flight).
        $rule->enabled = false;
        $rule->save();

        // Second evaluate: rule is disabled so not processed; orphaned sweep picks up the pending alert.
        $t1 = $this->t0->addSeconds(30);
        CarbonImmutable::setTestNow($t1);
        $summary = $this->engine->evaluate($t1);

        $this->assertSame(1, $summary->resolved);

        $alert = Alert::where('rule_key', 'infra-down')->where('target_id', $target->id)->first();
        $this->assertSame(AlertState::Resolved, $alert->state);
        $this->assertNull($alert->fired_at, 'Alert was never fired — should resolve silently');
        $this->assertEquals($t1->toDateTimeString(), $alert->resolved_at->toDateTimeString());

        // No events — never fired.
        Event::assertNotDispatched(AlertFired::class);
        Event::assertNotDispatched(AlertResolved::class);
    }

    public function test_deleted_rule_with_firing_alert_resolves_and_dispatches_alert_resolved(): void
    {
        // A rule is deleted (not in DB) while a firing alert exists → sweep resolves it
        // and dispatches AlertResolved exactly once.
        Event::fake();

        $target = Target::factory()->create();

        // Pre-existing firing alert for a rule that no longer exists.
        Alert::factory()->firing()->for($target)->create([
            'rule_key' => 'old-rule-gone',
            'fired_at' => $this->t0->subMinutes(10),
        ]);

        $summary = $this->engine->evaluate($this->t0);

        $this->assertSame(1, $summary->resolved);

        $alert = Alert::where('rule_key', 'old-rule-gone')->where('target_id', $target->id)->first();
        $this->assertSame(AlertState::Resolved, $alert->state);
        $this->assertNotNull($alert->fired_at);

        Event::assertDispatchedTimes(AlertResolved::class, 1);
        Event::assertDispatched(AlertResolved::class, fn ($e) => $e->alert->id === $alert->id);
    }

    // =========================================================================
    // Deleted target: target_id nulled, evaluate should not crash
    // =========================================================================

    public function test_deleted_target_with_open_alert_resolves_without_crash(): void
    {
        Event::fake();

        $rule = Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 180,
            'tier' => AlertTier::Critical->value,
        ]);

        // Alert with null target_id — target was deleted after alert was created.
        Alert::factory()->firing()->create([
            'target_id' => null,
            'rule_key' => 'infra-down',
            'fired_at' => $this->t0->subMinutes(10),
        ]);

        // Should not crash; rule has no breaching targets, so the alert is in openAlerts
        // but not in breaches → resolve branch runs; null target falls through vanish policy.
        $summary = $this->engine->evaluate($this->t0);

        $this->assertSame(1, $summary->resolved);

        $alert = Alert::where('rule_key', 'infra-down')->whereNull('target_id')->first();
        $this->assertSame(AlertState::Resolved, $alert->state);

        Event::assertDispatchedTimes(AlertResolved::class, 1);
    }

    // =========================================================================
    // Vanish policy: Unknown check status sustains open alert
    // =========================================================================

    public function test_unknown_check_status_sustains_firing_alert_and_no_resolved_event(): void
    {
        // Target goes down → pending → firing; check flips to Unknown (target vanished from Proxmox).
        // Evaluate → alert STILL firing, no AlertResolved dispatched.
        // Check flips Up → evaluate → resolves + AlertResolved dispatched.
        Event::fake();

        $rule = Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 0,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = $this->targetWithCheck('pve-node', TargetStatus::Down);

        // First evaluate: fires immediately (duration = 0).
        $this->engine->evaluate($this->t0);
        Event::assertDispatched(AlertFired::class);

        // Target "vanishes" — reconciler marks check Unknown.
        $check = $target->check;
        $check->status = TargetStatus::Unknown;
        $check->save();

        // Second evaluate: target no longer breaches (TargetDownCondition checks for Down, not Unknown).
        // Vanish policy: Unknown → sustain the alert, do NOT resolve.
        $t1 = $this->t0->addMinutes(1);
        CarbonImmutable::setTestNow($t1);
        $summary = $this->engine->evaluate($t1);

        $this->assertSame(0, $summary->resolved, 'Alert must be sustained when check is Unknown');

        $alert = Alert::where('rule_key', 'infra-down')->where('target_id', $target->id)->first();
        $this->assertSame(AlertState::Firing, $alert->state, 'Alert must remain Firing during Unknown');

        Event::assertNotDispatched(AlertResolved::class);

        // Target comes back Up — now resolves normally.
        $check->status = TargetStatus::Up;
        $check->save();

        $t2 = $t1->addMinutes(1);
        CarbonImmutable::setTestNow($t2);
        $summary2 = $this->engine->evaluate($t2);

        $this->assertSame(1, $summary2->resolved);

        $alert->refresh();
        $this->assertSame(AlertState::Resolved, $alert->state);
        $this->assertEquals($t2->toDateTimeString(), $alert->resolved_at->toDateTimeString());

        Event::assertDispatchedTimes(AlertResolved::class, 1);
    }

    // =========================================================================
    // Two rules breach same target → two independent open alerts
    // =========================================================================

    public function test_two_rules_breaching_same_target_create_two_independent_alerts(): void
    {
        Event::fake();

        Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 0,
            'tier' => AlertTier::Critical->value,
        ]);

        Rule::factory()->metricThreshold('disk_pct', '>=', 90)->create([
            'key' => 'disk-high',
            'duration_seconds' => 0,
            'tier' => AlertTier::Critical->value,
        ]);

        // Target is down AND has a high disk metric.
        $target = $this->targetWithCheck('storage-01', TargetStatus::Down);
        Metric::factory()->for($target)->create([
            'key' => 'disk_pct',
            'value' => 95.0,
            'captured_at' => $this->t0,
        ]);

        $summary = $this->engine->evaluate($this->t0);

        // Both rules fire immediately (duration = 0).
        $this->assertSame(2, $summary->pendingCreated);
        $this->assertSame(2, $summary->fired);
        $this->assertSame(0, $summary->resolved);

        $downAlert = Alert::where('rule_key', 'infra-down')->where('target_id', $target->id)->first();
        $this->assertNotNull($downAlert);
        $this->assertSame(AlertState::Firing, $downAlert->state);
        $this->assertSame('infra-down: storage-01', $downAlert->title);

        $diskAlert = Alert::where('rule_key', 'disk-high')->where('target_id', $target->id)->first();
        $this->assertNotNull($diskAlert);
        $this->assertSame(AlertState::Firing, $diskAlert->state);
        $this->assertStringContainsString('disk-high', $diskAlert->title);

        // Distinct alerts with distinct IDs.
        $this->assertNotSame($downAlert->id, $diskAlert->id);

        Event::assertDispatchedTimes(AlertFired::class, 2);
    }
}
