<?php

namespace Tests\Feature;

use App\Enums\AlertTier;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Enums\TokenAbility;
use App\Models\Alert;
use App\Models\Check;
use App\Models\Metric;
use App\Models\Target;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function readToken(User $user): string
    {
        return $user->createToken('mobile', [TokenAbility::Read->value])->plainTextToken;
    }

    private function ackToken(User $user): string
    {
        return $user->createToken('mobile-ack', [TokenAbility::AckAlerts->value])->plainTextToken;
    }

    private function fullToken(User $user): string
    {
        return $user->createToken('mobile-full', TokenAbility::values())->plainTextToken;
    }

    private function targetWithCheck(string $name, TargetStatus $status, TargetType $type = TargetType::Lxc): Target
    {
        $target = Target::factory()->create(['name' => $name, 'type' => $type]);
        Check::factory()->for($target)->create(['status' => $status->value]);

        return $target;
    }

    private function metric(Target $target, string $key, float $value, int $agoMinutes = 1): void
    {
        Metric::factory()->for($target)->create([
            'key' => $key,
            'value' => $value,
            'captured_at' => now()->subMinutes($agoMinutes),
        ]);
    }

    // =========================================================================
    // Authentication / unauthenticated
    // =========================================================================

    public function test_unauthenticated_requests_are_rejected_with_401(): void
    {
        $this->getJson('/api/v1/targets')->assertUnauthorized();
        $this->getJson('/api/v1/alerts')->assertUnauthorized();
        $this->getJson('/api/v1/summary')->assertUnauthorized();
        $this->getJson('/api/v1/logs')->assertUnauthorized();
    }

    // =========================================================================
    // Ability gates
    // =========================================================================

    public function test_read_token_can_access_all_get_endpoints(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $headers = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/v1/targets', $headers)->assertOk();
        $this->getJson('/api/v1/alerts', $headers)->assertOk();
        $this->getJson('/api/v1/summary', $headers)->assertOk();
        $this->getJson('/api/v1/logs', $headers)->assertOk();
    }

    public function test_read_token_cannot_ack_alerts(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        $alert = Alert::factory()->firing()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/alerts/{$alert->id}/ack")
            ->assertForbidden();
    }

    public function test_ack_only_token_can_acknowledge_alert(): void
    {
        $user = User::factory()->create();
        $token = $this->ackToken($user);
        $alert = Alert::factory()->firing()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/alerts/{$alert->id}/ack")
            ->assertOk();
    }

    public function test_ack_only_token_cannot_access_get_endpoints(): void
    {
        $user = User::factory()->create();
        $token = $this->ackToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/targets')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/alerts')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertForbidden();
    }

    // =========================================================================
    // GET /targets — shape and content
    // =========================================================================

    public function test_targets_returns_correct_json_structure(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $target = $this->targetWithCheck('web-01', TargetStatus::Up);
        $this->metric($target, 'cpu_pct', 22.5);
        $this->metric($target, 'mem_pct', 55.0);
        $this->metric($target, 'disk_pct', 30.0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/targets')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'type', 'status', 'node', 'cpuPercent', 'memPercent', 'diskPercent', 'latencyMs'],
                ],
            ]);
    }

    public function test_target_id_is_stringified_hub_numeric_id(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        $target = Target::factory()->create(['name' => 'alpha']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/targets')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $target->id);
    }

    public function test_target_with_no_check_row_has_status_unknown(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        // Create a target with NO check row.
        $target = Target::factory()->create(['name' => 'no-check']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/targets')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'unknown');
    }

    public function test_target_with_no_metrics_returns_null_percent_and_latency(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        $this->targetWithCheck('no-metrics', TargetStatus::Up);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/targets')
            ->assertOk()
            ->assertJsonPath('data.0.cpuPercent', null)
            ->assertJsonPath('data.0.memPercent', null)
            ->assertJsonPath('data.0.diskPercent', null)
            ->assertJsonPath('data.0.latencyMs', null);
    }

    public function test_target_with_stale_metrics_returns_nulls(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        $target = $this->targetWithCheck('stale', TargetStatus::Up);

        // Metric is 20 minutes old — outside the 15-minute staleness window.
        $this->metric($target, 'cpu_pct', 80.0, agoMinutes: 20);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/targets')
            ->assertOk()
            ->assertJsonPath('data.0.cpuPercent', null);
    }

    public function test_target_status_enum_value_is_returned(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        $this->targetWithCheck('down-host', TargetStatus::Down);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/targets')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'down');
    }

    public function test_target_type_enum_value_is_returned(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        Target::factory()->node()->create(['name' => 'pve-node']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/targets')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'node');
    }

    // =========================================================================
    // GET /targets/{id}
    // =========================================================================

    public function test_get_single_target_returns_correct_shape(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        $target = $this->targetWithCheck('srv-01', TargetStatus::Up);
        $this->metric($target, 'cpu_pct', 42.5);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/targets/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.id', (string) $target->id)
            ->assertJsonPath('data.name', 'srv-01')
            ->assertJsonPath('data.cpuPercent', 42.5);
    }

    public function test_get_unknown_target_returns_404(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/targets/99999')
            ->assertNotFound();
    }

    // =========================================================================
    // GET /alerts
    // =========================================================================

    public function test_alerts_returns_correct_json_structure(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        Alert::factory()->firing()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'tier', 'title', 'message', 'targetId', 'firedAt', 'acknowledged', 'resolvedAt'],
                ],
            ]);
    }

    public function test_alerts_only_returns_active_pending_and_firing_alerts(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $pending = Alert::factory()->pending()->create();
        $firing = Alert::factory()->firing()->create();
        Alert::factory()->resolved()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/alerts')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains((string) $pending->id, $ids);
        $this->assertContains((string) $firing->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_alerts_are_ordered_critical_first_then_fired_at_desc(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $t0 = CarbonImmutable::parse('2026-06-03 12:00:00');
        Carbon::setTestNow($t0);

        $warningOld = Alert::factory()->firing()->warning()->create([
            'fired_at' => $t0->subHours(3),
        ]);
        $criticalNew = Alert::factory()->firing()->create([
            'tier' => AlertTier::Critical,
            'fired_at' => $t0->subHour(),
        ]);
        $criticalOld = Alert::factory()->firing()->create([
            'tier' => AlertTier::Critical,
            'fired_at' => $t0->subHours(2),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/alerts')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        // Critical alerts first (newest first among criticals), then warnings.
        $this->assertSame([(string) $criticalNew->id, (string) $criticalOld->id, (string) $warningOld->id], $ids);
    }

    public function test_pending_alert_fired_at_falls_back_to_pending_since(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        $alert = Alert::factory()->pending()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.firedAt', $alert->pending_since->toIso8601String());
    }

    public function test_alert_id_is_stringified(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        $alert = Alert::factory()->firing()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $alert->id);
    }

    public function test_alert_target_id_is_stringified_or_null(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        $target = Target::factory()->create();
        Alert::factory()->firing()->for($target)->create();
        Alert::factory()->firing()->create(['target_id' => null]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/alerts')
            ->assertOk();

        $targetIds = collect($response->json('data'))->pluck('targetId');
        // One should be a string, one null.
        $this->assertContains((string) $target->id, $targetIds);
        $this->assertContains(null, $targetIds);
    }

    public function test_acknowledged_flag_is_true_when_acknowledged_at_is_set(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        Alert::factory()->acknowledged()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.acknowledged', true);
    }

    public function test_alert_resolved_at_is_always_null_on_active_alerts(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        Alert::factory()->firing()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.resolvedAt', null);
    }

    // =========================================================================
    // POST /alerts/{id}/ack
    // =========================================================================

    public function test_ack_sets_acknowledged_at_and_returns_200(): void
    {
        $user = User::factory()->create();
        $token = $this->fullToken($user);
        $alert = Alert::factory()->firing()->create(['acknowledged_at' => null]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/alerts/{$alert->id}/ack")
            ->assertOk()
            ->assertJsonPath('data.acknowledged', true);

        $alert->refresh();
        $this->assertNotNull($alert->acknowledged_at);
    }

    public function test_ack_is_idempotent_already_acked_returns_200(): void
    {
        $user = User::factory()->create();
        $token = $this->fullToken($user);
        $alert = Alert::factory()->acknowledged()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/alerts/{$alert->id}/ack")
            ->assertOk()
            ->assertJsonPath('data.acknowledged', true);
    }

    public function test_ack_unknown_alert_returns_404(): void
    {
        $user = User::factory()->create();
        $token = $this->fullToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/alerts/99999/ack')
            ->assertNotFound();
    }

    // =========================================================================
    // GET /summary
    // =========================================================================

    public function test_summary_returns_correct_json_structure(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonStructure([
                'targetsUp',
                'targetsTotal',
                'targetsPaused',
                'openAlerts',
                'nodes',
                'apps',
                'lastBackupAt',
                'lastBackupOk',
                'tankUsagePercent',
            ]);
    }

    public function test_summary_counts_targets_correctly(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        // 2 up, 1 paused, 1 with no check (unknown — counts as neither)
        $this->targetWithCheck('up-1', TargetStatus::Up);
        $this->targetWithCheck('up-2', TargetStatus::Up);
        $this->targetWithCheck('paused-1', TargetStatus::Paused);
        Target::factory()->create(['name' => 'no-check']); // no Check row
        // disabled target — should NOT be counted at all
        $disabled = Target::factory()->disabled()->create(['name' => 'disabled']);
        Check::factory()->for($disabled)->create(['status' => TargetStatus::Up->value]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonPath('targetsUp', 2)
            ->assertJsonPath('targetsTotal', 4)   // enabled only
            ->assertJsonPath('targetsPaused', 1);
    }

    public function test_summary_open_alerts_match_alert_endpoint_shape(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);
        Alert::factory()->firing()->create();

        // openAlerts is serialized as a flat array (no 'data' wrapper) when embedded
        // in response()->json([...]) — the data wrapper only applies to top-level responses.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonStructure(['openAlerts' => [['id', 'tier', 'title', 'message', 'targetId', 'firedAt', 'acknowledged', 'resolvedAt']]]);

        $firstAlert = $response->json('openAlerts.0');
        $this->assertIsString($firstAlert['id']);
        $this->assertContains($firstAlert['tier'], ['critical', 'warning']);
        $this->assertArrayHasKey('firedAt', $firstAlert);
    }

    public function test_summary_nodes_list_contains_only_node_type_targets(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        Target::factory()->node()->create(['name' => 'pve-01']);
        Target::factory()->node()->create(['name' => 'pve-02']);
        $this->targetWithCheck('lxc-01', TargetStatus::Up, TargetType::Lxc);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk();

        $nodeNames = collect($response->json('nodes'))->pluck('name');
        $this->assertContains('pve-01', $nodeNames);
        $this->assertContains('pve-02', $nodeNames);
        $this->assertNotContains('lxc-01', $nodeNames);
    }

    public function test_summary_apps_is_empty_list(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonPath('apps', []);
    }

    public function test_summary_last_backup_at_computed_from_backup_age_hours(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $t0 = CarbonImmutable::parse('2026-06-03 12:00:00');
        Carbon::setTestNow($t0);

        $storage = Target::factory()->storage()->create(['name' => 'tank', 'enabled' => true]);
        Metric::factory()->for($storage)->create([
            'key' => 'backup_age_hours',
            'value' => 10.0,
            'captured_at' => $t0->subMinutes(5),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk();

        // lastBackupAt should be approximately now - 10h
        $lastBackupAt = $response->json('lastBackupAt');
        $this->assertNotNull($lastBackupAt);
        $parsed = CarbonImmutable::parse($lastBackupAt);
        $expected = $t0->subHours(10);
        $this->assertEqualsWithDelta($expected->timestamp, $parsed->timestamp, 5);

        $this->assertTrue($response->json('lastBackupOk'));  // 10h <= 26h
    }

    public function test_summary_last_backup_ok_false_when_age_exceeds_26h(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $t0 = CarbonImmutable::parse('2026-06-03 12:00:00');
        Carbon::setTestNow($t0);

        $storage = Target::factory()->storage()->create(['name' => 'tank', 'enabled' => true]);
        Metric::factory()->for($storage)->create([
            'key' => 'backup_age_hours',
            'value' => 30.0,
            'captured_at' => $t0->subMinutes(5),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonPath('lastBackupOk', false);
    }

    public function test_summary_last_backup_null_when_no_metric(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonPath('lastBackupAt', null)
            ->assertJsonPath('lastBackupOk', false);
    }

    public function test_summary_tank_usage_uses_pbs_disk_pct_first(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $tank = Target::factory()->storage()->create(['name' => 'tank', 'enabled' => true]);
        $this->metric($tank, 'pbs_disk_pct', 77.5);
        $this->metric($tank, 'disk_pct', 55.0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonPath('tankUsagePercent', 77.5);
    }

    public function test_summary_tank_usage_falls_back_to_disk_pct(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $tank = Target::factory()->storage()->create(['name' => 'tank', 'enabled' => true]);
        $this->metric($tank, 'disk_pct', 44.5);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonPath('tankUsagePercent', 44.5);
    }

    public function test_summary_tank_usage_is_zero_when_no_tank_target(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonPath('tankUsagePercent', 0);
    }

    // =========================================================================
    // GET /logs
    // =========================================================================

    public function test_logs_returns_empty_array(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/logs')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_logs_accepts_filter_params_without_error(): void
    {
        $user = User::factory()->create();
        $token = $this->readToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/logs?host=pve-01&severity=error&search=oom&limit=50')
            ->assertOk()
            ->assertExactJson([]);
    }
}
