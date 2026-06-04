<?php

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Check;
use App\Models\Target;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CollectRunTest extends TestCase
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

    public function test_happy_path_writes_checks_for_fixture_targets(): void
    {
        $this->fakeWithFixture();

        $this->artisan('collect:run')->assertSuccessful();

        // Infra targets are auto-discovered from the fixture and get checks.
        $webApp = Target::where('name', 'web-app')->first();
        $this->assertNotNull($webApp);
        $this->assertNotNull($webApp->check);
        $this->assertSame(TargetStatus::Up, $webApp->check->status);

        $mediaServer = Target::where('name', 'media-server')->first();
        $this->assertNotNull($mediaServer->check);
        $this->assertSame(TargetStatus::Down, $mediaServer->check->status);

        $pve = Target::where('name', 'pve')->where('type', TargetType::Node->value)->first();
        $this->assertNotNull($pve->check);
        $this->assertSame(TargetStatus::Up, $pve->check->status);
    }

    public function test_collector_exception_marks_scoped_targets_unknown_and_command_still_succeeds(): void
    {
        // Pre-create some scoped infra targets with existing check rows (simulating prior runs).
        $pve = Target::factory()->node()->create(['name' => 'pve']);
        $webApp = Target::factory()->create(['type' => TargetType::Lxc, 'name' => 'web-app', 'node' => 'pve']);
        Check::factory()->for($pve)->up()->create();
        Check::factory()->for($webApp)->up()->create();

        // Proxmox returns 500 — CollectorException will be thrown
        Http::fake([
            'pve.test:8006/*' => Http::response([], 500),
            '*' => Http::response('OK', 200),
        ]);

        Log::spy();

        $this->artisan('collect:run')->assertSuccessful();

        // The scoped checks should now be Unknown
        $this->assertSame(TargetStatus::Unknown, $pve->fresh()->check->status);
        $this->assertSame(TargetStatus::Unknown, $webApp->fresh()->check->status);

        // A warning was logged
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_proxmox_failure_does_not_prevent_http_and_pbs_results_recording(): void
    {
        Carbon::setTestNow('2024-06-05 12:00:00');

        config([
            'watchtower.pbs.base_url' => 'https://pbs.test:8007',
            'watchtower.pbs.token_id' => 'watchtower@pbs!hub',
            'watchtower.pbs.token_secret' => 'test-pbs-secret',
            'watchtower.pbs.verify_tls' => false,
        ]);

        // Service target for HTTP collector
        $service = Target::factory()->service()->create([
            'name' => 'my-api',
            'check_config' => ['url' => 'http://192.168.1.1:8080/health'],
        ]);

        $pbsUsage = json_decode(
            file_get_contents(__DIR__.'/../Fixtures/pbs-datastore-usage.json'),
            true
        );
        $pbsGroupsRoot = json_decode(
            file_get_contents(__DIR__.'/../Fixtures/pbs-groups-backup-root.json'),
            true
        );

        Http::fake([
            // Proxmox returns 500 → CollectorException
            'pve.test:8006/*' => Http::response([], 500),
            // HTTP service is healthy
            'http://192.168.1.1:8080/health' => Http::response('OK', 200),
            // PBS is healthy — fixture has 'backup' (healthy) and 'backup-main' (broken)
            'pbs.test:8007/api2/json/status/datastore-usage' => Http::response($pbsUsage, 200),
            'pbs.test:8007/api2/json/admin/datastore/backup/namespace' => Http::response(['data' => [['ns' => '']]], 200),
            'pbs.test:8007/api2/json/admin/datastore/backup/groups' => Http::response($pbsGroupsRoot, 200),
        ]);

        Log::spy();

        $this->artisan('collect:run')->assertSuccessful();

        // HTTP and PBS results must be recorded despite Proxmox failure
        $this->assertNotNull($service->fresh()->check);
        $this->assertSame(TargetStatus::Up, $service->fresh()->check->status);

        // PBS auto-creates the storage target on first collection
        $backupTarget = Target::where('type', TargetType::Storage->value)
            ->where('name', 'backup')
            ->where('node', 'pbs')
            ->first();
        $this->assertNotNull($backupTarget);
        $this->assertNotNull($backupTarget->fresh()->check);
        $this->assertSame(TargetStatus::Up, $backupTarget->fresh()->check->status);

        // Proxmox failure was logged
        Log::shouldHaveReceived('warning')->once();

        Carbon::setTestNow(null);
    }
}
