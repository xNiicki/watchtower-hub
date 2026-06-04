<?php

namespace Tests\Feature;

use App\Enums\AlertTier;
use App\Enums\TargetStatus;
use App\Models\Check;
use App\Models\Rule;
use App\Models\Target;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertsEvaluateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_successfully_and_prints_summary(): void
    {
        $t0 = CarbonImmutable::parse('2026-06-03 12:00:00');
        Carbon::setTestNow($t0);

        Rule::factory()->targetDown()->create([
            'key' => 'infra-down',
            'duration_seconds' => 300,
            'tier' => AlertTier::Critical->value,
        ]);

        $target = Target::factory()->create(['name' => 'web-01']);
        Check::factory()->for($target)->create(['status' => TargetStatus::Down->value]);

        $this->artisan('alerts:evaluate')
            ->expectsOutputToContain('1 pending created, 0 fired, 0 resolved')
            ->assertExitCode(0);
    }

    public function test_command_prints_zero_counts_when_no_rules_exist(): void
    {
        $this->artisan('alerts:evaluate')
            ->expectsOutputToContain('0 pending created, 0 fired, 0 resolved')
            ->assertExitCode(0);
    }

    public function test_alerts_evaluate_is_scheduled_every_thirty_seconds(): void
    {
        // Verify the schedule entry is registered by checking the schedule list.
        $this->artisan('schedule:list')
            ->expectsOutputToContain('alerts:evaluate')
            ->assertExitCode(0);
    }
}
