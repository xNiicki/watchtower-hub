<?php

namespace Tests\Feature;

use App\Collectors\CollectorException;
use App\Collectors\PbsCollector;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Target;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PbsCollectorTest extends TestCase
{
    use RefreshDatabase;

    private PbsCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'watchtower.pbs.base_url' => 'https://pbs.test:8007',
            'watchtower.pbs.token_id' => 'watchtower@pbs!hub',
            'watchtower.pbs.token_secret' => 'test-pbs-secret',
            'watchtower.pbs.verify_tls' => false,
        ]);

        $this->collector = new PbsCollector;

        // Newest last-backup across both namespaces (root+offsite) is 1717567200 = 2024-06-05 06:00:00 UTC.
        // Pin now to 2024-06-05 12:00:00 UTC → expected age = 6.0 hours.
        Carbon::setTestNow('2024-06-05 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    private function loadFixture(string $filename): array
    {
        return json_decode(
            file_get_contents(__DIR__.'/../Fixtures/'.$filename),
            true
        );
    }

    /**
     * Fake the happy path: one healthy datastore ('backup') with two namespaces,
     * one broken datastore ('backup-main') with an error field.
     */
    private function fakeHappyPath(): void
    {
        $usage = $this->loadFixture('pbs-datastore-usage.json');
        $namespaces = $this->loadFixture('pbs-namespaces.json');
        $groupsRoot = $this->loadFixture('pbs-groups-backup-root.json');
        $groupsOffsite = $this->loadFixture('pbs-groups-backup-offsite.json');

        Http::fake([
            'pbs.test:8007/api2/json/status/datastore-usage' => Http::response($usage, 200),
            'pbs.test:8007/api2/json/admin/datastore/backup/namespace' => Http::response($namespaces, 200),
            'pbs.test:8007/api2/json/admin/datastore/backup/groups' => Http::response($groupsRoot, 200),
            'pbs.test:8007/api2/json/admin/datastore/backup/groups?ns=offsite' => Http::response($groupsOffsite, 200),
            // backup-main is broken (error field) — no further API calls are made for it.
        ]);
    }

    // =========================================================================
    // enabled() / scope()
    // =========================================================================

    public function test_enabled_when_configured(): void
    {
        $this->assertTrue($this->collector->enabled());
    }

    public function test_not_enabled_when_base_url_missing(): void
    {
        config(['watchtower.pbs.base_url' => null]);

        $this->assertFalse($this->collector->enabled());
    }

    public function test_not_enabled_when_token_missing(): void
    {
        config(['watchtower.pbs.token_id' => null]);

        $this->assertFalse($this->collector->enabled());
    }

    public function test_scope_is_empty(): void
    {
        $this->assertSame([], $this->collector->scope());
    }

    // =========================================================================
    // Auto-create target
    // =========================================================================

    public function test_healthy_datastore_auto_creates_storage_target_with_node_pbs(): void
    {
        $this->fakeHappyPath();

        $this->assertDatabaseCount('targets', 0);

        $this->collector->collect();

        // 'backup' — healthy → auto-created
        $this->assertDatabaseHas('targets', [
            'type' => TargetType::Storage->value,
            'name' => 'backup',
            'node' => 'pbs',
        ]);

        // 'backup-main' — broken → also auto-created (target exists, Down result)
        $this->assertDatabaseHas('targets', [
            'type' => TargetType::Storage->value,
            'name' => 'backup-main',
            'node' => 'pbs',
        ]);

        $this->assertDatabaseCount('targets', 2);
    }

    public function test_running_twice_does_not_duplicate_target(): void
    {
        $this->fakeHappyPath();
        $this->collector->collect();

        // Fake again for the second run
        $this->fakeHappyPath();
        $this->collector->collect();

        $this->assertDatabaseCount('targets', 2);
        $this->assertSame(
            1,
            Target::where('type', TargetType::Storage->value)->where('name', 'backup')->where('node', 'pbs')->count()
        );
    }

    // =========================================================================
    // Healthy datastore — disk_pct + backup age
    // =========================================================================

    public function test_healthy_datastore_returns_up_with_correct_disk_pct(): void
    {
        // backup: used=76450000000, total=107374182400 → 71.2%
        $this->fakeHappyPath();

        $results = $this->collector->collect();

        $result = collect($results)->first(fn ($r) => $r->target->name === 'backup');

        $this->assertNotNull($result);
        $this->assertSame(TargetStatus::Up, $result->status);
        $this->assertArrayHasKey('pbs_disk_pct', $result->metrics);
        $this->assertArrayNotHasKey('disk_pct', $result->metrics);
        $this->assertSame(round(76450000000 / 107374182400 * 100, 1), $result->metrics['pbs_disk_pct']);
    }

    public function test_multi_namespace_newest_backup_wins(): void
    {
        // root ns newest: 1717480800, offsite ns newest: 1717567200
        // Global newest = 1717567200 = 2024-06-05 06:00:00 UTC
        // Now pinned to 2024-06-05 12:00:00 UTC → age = 6.0 hours
        $this->fakeHappyPath();

        $results = $this->collector->collect();

        $result = collect($results)->first(fn ($r) => $r->target->name === 'backup');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('backup_age_hours', $result->metrics);
        $this->assertSame(6.0, $result->metrics['backup_age_hours']);
    }

    public function test_empty_datastore_returns_up_with_disk_pct_but_no_backup_age(): void
    {
        $usage = $this->loadFixture('pbs-datastore-usage.json');
        $namespaces = ['data' => [['ns' => '']]];

        Http::fake([
            'pbs.test:8007/api2/json/status/datastore-usage' => Http::response($usage, 200),
            'pbs.test:8007/api2/json/admin/datastore/backup/namespace' => Http::response($namespaces, 200),
            'pbs.test:8007/api2/json/admin/datastore/backup/groups' => Http::response(['data' => []], 200),
        ]);

        $results = $this->collector->collect();

        $result = collect($results)->first(fn ($r) => $r->target->name === 'backup');

        $this->assertNotNull($result);
        $this->assertSame(TargetStatus::Up, $result->status);
        $this->assertArrayHasKey('pbs_disk_pct', $result->metrics);
        $this->assertArrayNotHasKey('backup_age_hours', $result->metrics);
    }

    // =========================================================================
    // Broken datastore (error field)
    // =========================================================================

    public function test_broken_datastore_returns_down_with_no_metrics(): void
    {
        $this->fakeHappyPath();

        $results = $this->collector->collect();

        $result = collect($results)->first(fn ($r) => $r->target->name === 'backup-main');

        $this->assertNotNull($result);
        $this->assertSame(TargetStatus::Down, $result->status);
        $this->assertSame([], $result->metrics);
    }

    // =========================================================================
    // Totalless datastore (no error field, no total — malformed/edge response)
    // =========================================================================

    public function test_totalless_datastore_returns_down_no_metrics_and_logs_warning(): void
    {
        // A datastore item with no 'error' field but also no 'total' — e.g. a malformed
        // or edge-case API response. The collector must NOT divide and must surface Down.
        $usage = [
            'data' => [
                ['store' => 'orphan', 'mount-status' => 'mounted'],
            ],
        ];

        Http::fake([
            'pbs.test:8007/api2/json/status/datastore-usage' => Http::response($usage, 200),
        ]);

        Log::spy();

        $results = $this->collector->collect();

        $result = collect($results)->first(fn ($r) => $r->target->name === 'orphan');

        $this->assertNotNull($result);
        $this->assertSame(TargetStatus::Down, $result->status);
        $this->assertSame([], $result->metrics);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'orphan') && str_contains($msg, 'no usable total'));
    }

    // =========================================================================
    // Disabled target
    // =========================================================================

    public function test_disabled_pbs_target_returns_paused_with_no_api_calls_for_it(): void
    {
        // Pre-create the target as disabled — collector should return Paused and skip further API calls.
        Target::factory()->storage()->disabled()->create(['name' => 'backup', 'node' => 'pbs']);

        $usage = $this->loadFixture('pbs-datastore-usage.json');

        Http::fake([
            'pbs.test:8007/api2/json/status/datastore-usage' => Http::response($usage, 200),
            // No namespace or groups calls expected for 'backup'.
            // backup-main is broken → also no namespace/groups calls.
        ]);

        $results = $this->collector->collect();

        $result = collect($results)->first(fn ($r) => $r->target->name === 'backup');

        $this->assertNotNull($result);
        $this->assertSame(TargetStatus::Paused, $result->status);
        $this->assertSame([], $result->metrics);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/namespace'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/groups'));
    }

    // =========================================================================
    // Auth header
    // =========================================================================

    public function test_colon_auth_header_sent_to_pbs(): void
    {
        $this->fakeHappyPath();

        $this->collector->collect();

        // PBS uses PBSAPIToken={id}:{secret} — colon separator, unlike Proxmox VE's equals sign.
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'PBSAPIToken=watchtower@pbs!hub:test-pbs-secret');
        });
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function test_datastore_usage_500_throws_collector_exception(): void
    {
        Http::fake([
            'pbs.test:8007/api2/json/status/datastore-usage' => Http::response([], 500),
        ]);

        $this->expectException(CollectorException::class);

        $this->collector->collect();
    }

    public function test_per_datastore_groups_500_still_records_disk_pct_and_logs_warning(): void
    {
        // The groups call fails → per-datastore isolation: disk_pct is still recorded,
        // a warning is logged, and no exception escapes the collector.
        $usage = $this->loadFixture('pbs-datastore-usage.json');
        $namespaces = ['data' => [['ns' => '']]];

        Http::fake([
            'pbs.test:8007/api2/json/status/datastore-usage' => Http::response($usage, 200),
            'pbs.test:8007/api2/json/admin/datastore/backup/namespace' => Http::response($namespaces, 200),
            'pbs.test:8007/api2/json/admin/datastore/backup/groups' => Http::response([], 500),
        ]);

        Log::spy();

        $results = $this->collector->collect();

        $result = collect($results)->first(fn ($r) => $r->target->name === 'backup');

        $this->assertNotNull($result);
        $this->assertSame(TargetStatus::Up, $result->status);
        $this->assertArrayHasKey('pbs_disk_pct', $result->metrics);
        $this->assertArrayNotHasKey('backup_age_hours', $result->metrics);

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_namespace_endpoint_failing_falls_back_to_root_namespace(): void
    {
        $usage = $this->loadFixture('pbs-datastore-usage.json');
        $groupsRoot = $this->loadFixture('pbs-groups-backup-root.json');

        Http::fake([
            'pbs.test:8007/api2/json/status/datastore-usage' => Http::response($usage, 200),
            'pbs.test:8007/api2/json/admin/datastore/backup/namespace' => Http::response([], 500),
            'pbs.test:8007/api2/json/admin/datastore/backup/groups' => Http::response($groupsRoot, 200),
        ]);

        $results = $this->collector->collect();

        $result = collect($results)->first(fn ($r) => $r->target->name === 'backup');

        // Root ns newest is 1717480800 = 2024-06-04 06:00:00 UTC
        // Now pinned to 2024-06-05 12:00:00 UTC → age = 30.0 hours
        $this->assertNotNull($result);
        $this->assertSame(TargetStatus::Up, $result->status);
        $this->assertArrayHasKey('backup_age_hours', $result->metrics);
        $this->assertSame(30.0, $result->metrics['backup_age_hours']);
    }
}
