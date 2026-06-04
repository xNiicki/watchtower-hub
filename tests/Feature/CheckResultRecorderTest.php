<?php

namespace Tests\Feature;

use App\Collectors\CheckResult;
use App\Enums\TargetStatus;
use App\Models\Check;
use App\Models\Metric;
use App\Models\Target;
use App\Services\CheckResultRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckResultRecorderTest extends TestCase
{
    use RefreshDatabase;

    private CheckResultRecorder $recorder;

    private CarbonImmutable $capturedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = new CheckResultRecorder;
        $this->capturedAt = CarbonImmutable::parse('2026-06-03 12:00:00');
    }

    public function test_up_result_resets_fail_streak_and_sets_last_ok_at(): void
    {
        $target = Target::factory()->create();

        // Prime with an existing down check
        Check::factory()->for($target)->down(3)->create();

        $result = new CheckResult($target, TargetStatus::Up, 42);
        $this->recorder->record($result, $this->capturedAt);

        $check = $target->fresh()->check;

        $this->assertSame(TargetStatus::Up, $check->status);
        $this->assertSame(0, $check->fail_streak);
        $this->assertSame(42, $check->latency_ms);
        $this->assertEquals($this->capturedAt->toDateTimeString(), $check->last_ok_at->toDateTimeString());
        $this->assertEquals($this->capturedAt->toDateTimeString(), $check->last_checked_at->toDateTimeString());
    }

    public function test_down_result_increments_fail_streak(): void
    {
        $target = Target::factory()->create();

        $first = new CheckResult($target, TargetStatus::Down);
        $this->recorder->record($first, $this->capturedAt);

        $check = $target->fresh()->check;
        $this->assertSame(1, $check->fail_streak);

        $second = new CheckResult($target, TargetStatus::Down);
        $this->recorder->record($second, $this->capturedAt->addMinute());

        $check = $target->fresh()->check;
        $this->assertSame(2, $check->fail_streak);
        $this->assertSame(TargetStatus::Down, $check->status);
    }

    public function test_unknown_result_preserves_streak(): void
    {
        $target = Target::factory()->create();

        // First establish a streak
        $this->recorder->record(new CheckResult($target, TargetStatus::Down), $this->capturedAt);
        $this->recorder->record(new CheckResult($target, TargetStatus::Down), $this->capturedAt->addMinute());

        $streakBefore = $target->fresh()->check->fail_streak;
        $this->assertSame(2, $streakBefore);

        // Then record unknown
        $this->recorder->record(new CheckResult($target, TargetStatus::Unknown), $this->capturedAt->addMinutes(2));

        $check = $target->fresh()->check;
        $this->assertSame(2, $check->fail_streak);
        $this->assertSame(TargetStatus::Unknown, $check->status);
    }

    public function test_paused_result_preserves_streak(): void
    {
        $target = Target::factory()->create();

        $this->recorder->record(new CheckResult($target, TargetStatus::Down), $this->capturedAt);
        $this->recorder->record(new CheckResult($target, TargetStatus::Down), $this->capturedAt->addMinute());

        $this->recorder->record(new CheckResult($target, TargetStatus::Paused), $this->capturedAt->addMinutes(2));

        $check = $target->fresh()->check;
        $this->assertSame(2, $check->fail_streak);
        $this->assertSame(TargetStatus::Paused, $check->status);
    }

    public function test_metrics_are_inserted_with_correct_captured_at(): void
    {
        $target = Target::factory()->create();

        $result = new CheckResult($target, TargetStatus::Up, null, [
            'cpu_pct' => 14.2,
            'mem_pct' => 48.8,
        ]);

        $this->recorder->record($result, $this->capturedAt);

        $this->assertDatabaseCount('metrics', 2);

        $this->assertDatabaseHas('metrics', [
            'target_id' => $target->id,
            'key' => 'cpu_pct',
            'value' => 14.2,
        ]);

        $this->assertDatabaseHas('metrics', [
            'target_id' => $target->id,
            'key' => 'mem_pct',
            'value' => 48.8,
        ]);

        $metric = Metric::where('target_id', $target->id)->where('key', 'cpu_pct')->first();
        $this->assertEquals(
            $this->capturedAt->toDateTimeString(),
            $metric->captured_at->toDateTimeString()
        );
    }

    public function test_no_metrics_inserts_no_metric_rows(): void
    {
        $target = Target::factory()->create();

        $result = new CheckResult($target, TargetStatus::Up);
        $this->recorder->record($result, $this->capturedAt);

        $this->assertDatabaseCount('metrics', 0);
    }

    public function test_creates_check_if_none_exists(): void
    {
        $target = Target::factory()->create();

        $this->assertDatabaseCount('checks', 0);

        $this->recorder->record(new CheckResult($target, TargetStatus::Up, 10), $this->capturedAt);

        $this->assertDatabaseCount('checks', 1);
    }
}
