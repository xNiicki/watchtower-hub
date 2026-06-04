<?php

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Check;
use App\Models\Target;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VanishedTargetReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'watchtower.proxmox.base_url' => 'https://pve.test:8006',
            'watchtower.proxmox.token_id' => 'watchtower@pam!hub',
            'watchtower.proxmox.token_secret' => 'test-secret',
            'watchtower.proxmox.verify_tls' => false,
        ]);
    }

    private function fakeWithFixture(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/../Fixtures/proxmox-cluster-resources.json'),
            true
        );

        Http::fake([
            'pve.test:8006/*' => Http::response($fixture, 200),
            '*' => Http::response('OK', 200),
        ]);
    }

    public function test_vanished_target_with_check_is_marked_unknown(): void
    {
        $this->seed(FleetSeeder::class);

        // A target that IS in the seeder but NOT in the fixture response
        $vanished = Target::factory()->create([
            'type' => TargetType::Lxc->value,
            'name' => 'vanished-vm',
            'node' => 'pve',
        ]);

        // Give it a pre-existing Up check row
        Check::factory()->for($vanished)->up()->create();

        $this->assertSame(TargetStatus::Up, $vanished->fresh()->check->status);

        $this->fakeWithFixture();

        $this->artisan('collect:run')->assertSuccessful();

        $check = $vanished->fresh()->check;
        $this->assertNotNull($check);
        $this->assertSame(TargetStatus::Unknown, $check->status);
        // fail_streak must not be touched by reconciliation
        $this->assertSame(0, $check->fail_streak);
        // last_ok_at must not be touched by reconciliation
        $this->assertNotNull($check->last_ok_at);
    }

    public function test_in_scope_target_without_check_row_is_not_touched(): void
    {
        $this->seed(FleetSeeder::class);

        // An in-scope target with NO check row — reconciliation must not create one
        $noCheck = Target::factory()->create([
            'type' => TargetType::Lxc->value,
            'name' => 'never-checked',
            'node' => 'pve',
        ]);

        $this->assertNull($noCheck->fresh()->check);

        $this->fakeWithFixture();

        $this->artisan('collect:run')->assertSuccessful();

        // Still no check row for this target
        $this->assertNull($noCheck->fresh()->check);
    }

    public function test_pbs_owned_storage_target_is_not_marked_unknown_by_proxmox_reconciliation(): void
    {
        $this->seed(FleetSeeder::class);

        // PBS-owned storage target (node='pbs') — absent from PVE cluster/resources fixture.
        // Proxmox reconciliation must skip it because PBS is the sole authority on these targets.
        $pbsDatastore = Target::factory()->create([
            'type' => TargetType::Storage->value,
            'name' => 'backup',
            'node' => 'pbs',
        ]);

        Check::factory()->for($pbsDatastore)->up()->create();

        $this->assertSame(TargetStatus::Up, $pbsDatastore->fresh()->check->status);

        $this->fakeWithFixture();

        $this->artisan('collect:run')->assertSuccessful();

        // Status must remain Up — Proxmox reconciliation must have skipped this target.
        $this->assertSame(TargetStatus::Up, $pbsDatastore->fresh()->check->status);
    }

    public function test_genuinely_vanished_pve_storage_target_is_still_marked_unknown(): void
    {
        $this->seed(FleetSeeder::class);

        // A PVE-owned storage target (node='backup') that is absent from the fixture.
        // Proxmox reconciliation must still mark it Unknown — that behaviour must be preserved.
        $pveStorage = Target::factory()->create([
            'type' => TargetType::Storage->value,
            'name' => 'local-lvm',
            'node' => 'backup',
        ]);

        Check::factory()->for($pveStorage)->up()->create();

        $this->assertSame(TargetStatus::Up, $pveStorage->fresh()->check->status);

        $this->fakeWithFixture();

        $this->artisan('collect:run')->assertSuccessful();

        // Must be Unknown — it is genuinely absent from the Proxmox response.
        $this->assertSame(TargetStatus::Unknown, $pveStorage->fresh()->check->status);
    }
}
