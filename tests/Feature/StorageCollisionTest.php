<?php

namespace Tests\Feature;

use App\Collectors\ProxmoxCollector;
use App\Enums\TargetType;
use App\Models\Target;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorageCollisionTest extends TestCase
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

    public function test_storage_local_on_two_nodes_creates_two_distinct_targets(): void
    {
        // Fixture: `local` storage appears on both `pve` and `backup` nodes.
        // With the per-node unique constraint, these must be two distinct rows.
        $fixture = [
            'data' => [
                [
                    'type' => 'node',
                    'node' => 'pve',
                    'status' => 'online',
                ],
                [
                    'type' => 'node',
                    'node' => 'backup',
                    'status' => 'online',
                ],
                [
                    'type' => 'storage',
                    'storage' => 'local',
                    'node' => 'pve',
                    'status' => 'available',
                    'disk' => 10000000,
                    'maxdisk' => 100000000,
                ],
                [
                    'type' => 'storage',
                    'storage' => 'local',
                    'node' => 'backup',
                    'status' => 'available',
                    'disk' => 5000000,
                    'maxdisk' => 50000000,
                ],
            ],
        ];

        Http::fake([
            'pve.test:8006/*' => Http::response($fixture, 200),
        ]);

        $collector = new ProxmoxCollector;

        // Must not throw a constraint violation
        $results = $collector->collect();

        $storageResults = collect($results)->filter(
            fn ($r) => $r->target->type === TargetType::Storage && $r->target->name === 'local'
        );

        $this->assertCount(2, $storageResults);

        $pveStorage = Target::where('type', TargetType::Storage->value)
            ->where('name', 'local')
            ->where('node', 'pve')
            ->first();

        $backupStorage = Target::where('type', TargetType::Storage->value)
            ->where('name', 'local')
            ->where('node', 'backup')
            ->first();

        $this->assertNotNull($pveStorage);
        $this->assertNotNull($backupStorage);
        $this->assertNotSame($pveStorage->id, $backupStorage->id);
    }
}
