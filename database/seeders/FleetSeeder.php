<?php

namespace Database\Seeders;

use App\Enums\TargetType;
use App\Models\Target;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Example service-check targets.
 *
 * This seeder is a TEMPLATE, not real infrastructure. Proxmox/PBS infra targets
 * (nodes, storage, LXC, VMs, datastores) auto-discover at runtime via the Proxmox
 * collector — there is no need to seed them.
 *
 * HTTP service checks do NOT auto-discover, so a couple of disabled examples are
 * seeded here. Edit them to point at your own endpoints and flip `enabled => true`,
 * or add your own entries, to monitor any non-Proxmox HTTP service.
 *
 * Do NOT put real hostnames here — keep this file generic for the public repo.
 * Idempotent: matches on (type, name) so re-running updates rather than duplicates.
 */
class FleetSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Example HTTP service checks. All disabled by default — these are templates.
        // Replace the placeholder *.example.local URLs with your own and enable them.
        $exampleServices = [
            ['name' => 'homeassistant', 'url' => 'https://home-assistant.example.local/'],
            ['name' => 'jellyfin', 'url' => 'https://jellyfin.example.local/health'],
        ];

        foreach ($exampleServices as $service) {
            Target::updateOrCreate(
                ['type' => TargetType::Service->value, 'name' => $service['name']],
                [
                    'external_id' => null,
                    'node' => null,
                    'check_config' => ['url' => $service['url'], 'timeout_ms' => 6000, 'verify_tls' => true],
                    'enabled' => false,
                ],
            );
        }
    }
}
