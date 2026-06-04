<?php

namespace Tests\Feature;

use App\Collectors\CollectorException;
use App\Collectors\ProxmoxCollector;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Target;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProxmoxCollectorTest extends TestCase
{
    use RefreshDatabase;

    private ProxmoxCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'watchtower.proxmox.base_url' => 'https://pve.test:8006',
            'watchtower.proxmox.token_id' => 'watchtower@pam!hub',
            'watchtower.proxmox.token_secret' => 'test-secret',
            'watchtower.proxmox.verify_tls' => false,
        ]);

        $this->collector = new ProxmoxCollector;
    }

    private function fakeWithFixture(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/../Fixtures/proxmox-cluster-resources.json'),
            true
        );

        Http::fake([
            'pve.test:8006/*' => Http::response($fixture, 200),
        ]);
    }

    public function test_enabled_when_configured(): void
    {
        $this->assertTrue($this->collector->enabled());
    }

    public function test_not_enabled_when_base_url_missing(): void
    {
        config(['watchtower.proxmox.base_url' => null]);

        $this->assertFalse($this->collector->enabled());
    }

    public function test_maps_running_lxc_to_up(): void
    {
        $this->fakeWithFixture();

        $results = $this->collector->collect();

        $webApp = collect($results)->first(fn ($r) => $r->target->name === 'web-app');

        $this->assertNotNull($webApp);
        $this->assertSame(TargetStatus::Up, $webApp->status);
    }

    public function test_maps_stopped_lxc_to_down(): void
    {
        $this->fakeWithFixture();

        $results = $this->collector->collect();

        $mediaServer = collect($results)->first(fn ($r) => $r->target->name === 'media-server');

        $this->assertNotNull($mediaServer);
        $this->assertSame(TargetStatus::Down, $mediaServer->status);
        $this->assertEmpty($mediaServer->metrics);
    }

    public function test_enabled_guest_with_status_stopped_maps_to_down_no_metrics(): void
    {
        Http::fake([
            'pve.test:8006/*' => Http::response([
                'data' => [[
                    'type' => 'lxc',
                    'vmid' => 200,
                    'name' => 'stopped-guest',
                    'node' => 'pve',
                    'status' => 'stopped',
                    'cpu' => 0.0,
                    'maxcpu' => 2,
                    'mem' => 0,
                    'maxmem' => 2147483648,
                    'disk' => 1073741824,
                    'maxdisk' => 8589934592,
                ]],
            ], 200),
        ]);

        $results = $this->collector->collect();

        $guest = collect($results)->first(fn ($r) => $r->target->name === 'stopped-guest');

        $this->assertNotNull($guest);
        $this->assertSame(TargetStatus::Down, $guest->status);
        $this->assertEmpty($guest->metrics);
    }

    public function test_node_status_offline_maps_to_down(): void
    {
        Http::fake([
            'pve.test:8006/*' => Http::response([
                'data' => [[
                    'type' => 'node',
                    'node' => 'dead-node',
                    'status' => 'offline',
                    'cpu' => 0.0,
                    'maxcpu' => 4,
                    'mem' => 0,
                    'maxmem' => 8589934592,
                ]],
            ], 200),
        ]);

        $results = $this->collector->collect();

        $node = collect($results)->first(fn ($r) => $r->target->name === 'dead-node');

        $this->assertNotNull($node);
        $this->assertSame(TargetStatus::Down, $node->status);
        $this->assertEmpty($node->metrics);
    }

    public function test_disabled_target_returns_paused_no_metrics(): void
    {
        // Pre-create the discovered guest as disabled; the collector should report it
        // as Paused and skip metrics regardless of the running state in the fixture.
        Target::factory()->create([
            'type' => TargetType::Lxc,
            'name' => 'docs-app',
            'node' => 'pve',
            'enabled' => false,
        ]);
        $this->fakeWithFixture();

        $results = $this->collector->collect();

        $docsApp = collect($results)->first(fn ($r) => $r->target->name === 'docs-app');

        $this->assertNotNull($docsApp);
        $this->assertSame(TargetStatus::Paused, $docsApp->status);
        $this->assertEmpty($docsApp->metrics);
    }

    public function test_computes_mem_pct_correctly_from_bytes(): void
    {
        $this->fakeWithFixture();

        $results = $this->collector->collect();

        // web-app: mem=524288000, maxmem=1073741824 => 524288000/1073741824*100 = 48.828... => 48.8
        $webApp = collect($results)->first(fn ($r) => $r->target->name === 'web-app');

        $this->assertNotNull($webApp);
        $this->assertArrayHasKey('mem_pct', $webApp->metrics);
        $this->assertSame(48.8, $webApp->metrics['mem_pct']);
    }

    public function test_computes_cpu_pct_correctly(): void
    {
        $this->fakeWithFixture();

        $results = $this->collector->collect();

        // web-app: cpu=0.14 => 0.14*100 = 14.0
        $webApp = collect($results)->first(fn ($r) => $r->target->name === 'web-app');

        $this->assertNotNull($webApp);
        $this->assertArrayHasKey('cpu_pct', $webApp->metrics);
        $this->assertSame(14.0, $webApp->metrics['cpu_pct']);
    }

    public function test_auto_creates_undiscovered_target(): void
    {
        $this->fakeWithFixture();

        // new-discovery is in the fixture but has never been seen before
        $this->assertDatabaseMissing('targets', ['name' => 'new-discovery']);

        $this->collector->collect();

        $target = Target::where('name', 'new-discovery')->first();

        $this->assertNotNull($target);
        $this->assertSame(TargetType::Lxc, $target->type);
        $this->assertSame('pve', $target->node);
        $this->assertSame('106', $target->external_id);
    }

    public function test_auth_header_is_sent(): void
    {
        $this->fakeWithFixture();

        $this->collector->collect();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'cluster/resources')
                && $request->hasHeader('Authorization', 'PVEAPIToken=watchtower@pam!hub=test-secret');
        });
    }

    public function test_maps_node_to_node_type(): void
    {
        $this->fakeWithFixture();

        $results = $this->collector->collect();

        $pveNode = collect($results)->first(fn ($r) => $r->target->name === 'pve' && $r->target->type === TargetType::Node);

        $this->assertNotNull($pveNode);
        $this->assertSame(TargetStatus::Up, $pveNode->status);
    }

    public function test_maps_qemu_to_vm_type(): void
    {
        $this->fakeWithFixture();

        $results = $this->collector->collect();

        $vmApp = collect($results)->first(fn ($r) => $r->target->name === 'vm-app');

        $this->assertNotNull($vmApp);
        $this->assertSame(TargetType::Vm, $vmApp->target->type);
        $this->assertSame(TargetStatus::Up, $vmApp->status);
    }

    public function test_maps_storage_to_storage_type(): void
    {
        $this->fakeWithFixture();

        $results = $this->collector->collect();

        $tank = collect($results)->first(fn ($r) => $r->target->name === 'tank');

        $this->assertNotNull($tank);
        $this->assertSame(TargetType::Storage, $tank->target->type);
        $this->assertSame(TargetStatus::Up, $tank->status);
    }

    public function test_throws_collector_exception_on_non_2xx(): void
    {
        Http::fake([
            'pve.test:8006/*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $this->expectException(CollectorException::class);

        $this->collector->collect();
    }
}
