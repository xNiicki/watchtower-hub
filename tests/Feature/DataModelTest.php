<?php

namespace Tests\Feature;

use App\Enums\AlertState;
use App\Enums\AlertTier;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Alert;
use App\Models\Check;
use App\Models\Metric;
use App\Models\Target;
use Database\Seeders\FleetSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function test_target_has_one_check(): void
    {
        $target = Target::factory()->create();
        $check = Check::factory()->for($target)->create();

        $this->assertTrue($target->check->is($check));
    }

    public function test_target_has_many_metrics(): void
    {
        $target = Target::factory()->create();
        Metric::factory()->count(3)->for($target)->create();

        $this->assertCount(3, $target->metrics);
    }

    public function test_target_has_many_alerts(): void
    {
        $target = Target::factory()->create();
        Alert::factory()->count(2)->for($target)->create();

        $this->assertCount(2, $target->alerts);
    }

    public function test_check_belongs_to_target(): void
    {
        $target = Target::factory()->create();
        $check = Check::factory()->for($target)->create();

        $this->assertTrue($check->target->is($target));
    }

    public function test_metric_belongs_to_target(): void
    {
        $target = Target::factory()->create();
        $metric = Metric::factory()->for($target)->create();

        $this->assertTrue($metric->target->is($target));
    }

    public function test_alert_belongs_to_target(): void
    {
        $target = Target::factory()->create();
        $alert = Alert::factory()->for($target)->create();

        $this->assertTrue($alert->target->is($target));
    }

    // -------------------------------------------------------------------------
    // Enum casts round-trip
    // -------------------------------------------------------------------------

    public function test_target_type_cast_round_trips(): void
    {
        $target = Target::factory()->node()->create();

        $fresh = $target->fresh();

        $this->assertInstanceOf(TargetType::class, $fresh->type);
        $this->assertSame(TargetType::Node, $fresh->type);
        $this->assertSame('node', $fresh->type->value);
    }

    public function test_check_status_cast_round_trips(): void
    {
        $target = Target::factory()->create();
        $check = Check::factory()->for($target)->down(5)->create();

        $fresh = $check->fresh();

        $this->assertInstanceOf(TargetStatus::class, $fresh->status);
        $this->assertSame(TargetStatus::Down, $fresh->status);
        $this->assertSame('down', $fresh->status->value);
    }

    public function test_alert_state_and_tier_cast_round_trips(): void
    {
        $target = Target::factory()->create();
        $alert = Alert::factory()->for($target)->firing()->create(['tier' => AlertTier::Warning]);

        $fresh = $alert->fresh();

        $this->assertInstanceOf(AlertState::class, $fresh->state);
        $this->assertSame(AlertState::Firing, $fresh->state);
        $this->assertInstanceOf(AlertTier::class, $fresh->tier);
        $this->assertSame(AlertTier::Warning, $fresh->tier);
    }

    public function test_target_check_config_is_cast_to_array(): void
    {
        $target = Target::factory()->service()->create();

        $fresh = $target->fresh();

        $this->assertIsArray($fresh->check_config);
        $this->assertArrayHasKey('url', $fresh->check_config);
        $this->assertArrayHasKey('timeout_ms', $fresh->check_config);
    }

    // -------------------------------------------------------------------------
    // Cascade deletes
    // -------------------------------------------------------------------------

    public function test_deleting_target_cascades_to_check_and_metrics(): void
    {
        $target = Target::factory()->create();
        $check = Check::factory()->for($target)->create();
        Metric::factory()->count(3)->for($target)->create();

        $targetId = $target->id;
        $checkId = $check->id;

        $target->delete();

        $this->assertDatabaseMissing('checks', ['id' => $checkId]);
        $this->assertDatabaseMissing('metrics', ['target_id' => $targetId]);
    }

    public function test_deleting_target_nulls_alert_target_id(): void
    {
        $target = Target::factory()->create();
        $alert = Alert::factory()->for($target)->create();

        $target->delete();

        $this->assertNull($alert->fresh()->target_id);
    }

    // -------------------------------------------------------------------------
    // Constraints
    // -------------------------------------------------------------------------

    public function test_type_name_unique_constraint_is_enforced(): void
    {
        Target::factory()->create(['type' => TargetType::Lxc, 'name' => 'my-container']);

        $this->expectException(UniqueConstraintViolationException::class);

        Target::factory()->create(['type' => TargetType::Lxc, 'name' => 'my-container']);
    }

    public function test_same_name_different_type_is_allowed(): void
    {
        Target::factory()->create(['type' => TargetType::Lxc, 'name' => 'duplicate-name']);
        Target::factory()->create(['type' => TargetType::Vm, 'name' => 'duplicate-name']);

        $this->assertDatabaseCount('targets', 2);
    }

    public function test_one_check_per_target_unique_constraint_enforced(): void
    {
        $target = Target::factory()->create();
        Check::factory()->for($target)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Check::factory()->for($target)->create();
    }

    // -------------------------------------------------------------------------
    // Factory states — alert timestamp coherence
    // -------------------------------------------------------------------------

    public function test_pending_alert_has_no_fired_or_resolved_timestamps(): void
    {
        $alert = Alert::factory()->pending()->create();

        $this->assertSame(AlertState::Pending, $alert->state);
        $this->assertNotNull($alert->pending_since);
        $this->assertNull($alert->fired_at);
        $this->assertNull($alert->resolved_at);
        $this->assertNull($alert->acknowledged_at);
    }

    public function test_firing_alert_has_pending_since_and_fired_at(): void
    {
        $alert = Alert::factory()->firing()->create();

        $this->assertSame(AlertState::Firing, $alert->state);
        $this->assertNotNull($alert->pending_since);
        $this->assertNotNull($alert->fired_at);
        $this->assertNull($alert->resolved_at);
        $this->assertNull($alert->acknowledged_at);
    }

    public function test_resolved_alert_has_resolved_at(): void
    {
        $alert = Alert::factory()->resolved()->create();

        $this->assertSame(AlertState::Resolved, $alert->state);
        $this->assertNotNull($alert->pending_since);
        $this->assertNotNull($alert->fired_at);
        $this->assertNotNull($alert->resolved_at);
        $this->assertNull($alert->acknowledged_at);
    }

    public function test_acknowledged_alert_is_firing_with_acknowledged_at(): void
    {
        $alert = Alert::factory()->acknowledged()->create();

        $this->assertSame(AlertState::Firing, $alert->state);
        $this->assertNotNull($alert->pending_since);
        $this->assertNotNull($alert->fired_at);
        $this->assertNull($alert->resolved_at);
        $this->assertNotNull($alert->acknowledged_at);
    }

    // -------------------------------------------------------------------------
    // FleetSeeder
    // -------------------------------------------------------------------------

    public function test_fleet_seeder_seeds_only_example_services(): void
    {
        $this->seed(FleetSeeder::class);

        // The seeder only seeds example HTTP service checks; infra auto-discovers
        // via the Proxmox collector and is not seeded.
        $this->assertDatabaseCount('targets', 2);
        $this->assertSame(2, Target::where('type', TargetType::Service)->count());
    }

    public function test_fleet_seeder_is_idempotent(): void
    {
        $this->seed(FleetSeeder::class);
        $this->seed(FleetSeeder::class);

        $this->assertDatabaseCount('targets', 2);
    }

    public function test_fleet_seeder_example_services_are_disabled_by_default(): void
    {
        $this->seed(FleetSeeder::class);

        $this->assertSame(0, Target::where('enabled', true)->count());
        $this->assertSame(2, Target::where('enabled', false)->count());
    }

    public function test_metric_has_no_timestamps(): void
    {
        $target = Target::factory()->create();
        $metric = Metric::factory()->for($target)->create();

        // $timestamps = false means created_at/updated_at columns don't exist
        $this->assertNull($metric->created_at);
        $this->assertNull($metric->updated_at);
    }
}
