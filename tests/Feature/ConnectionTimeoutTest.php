<?php

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Check;
use App\Models\Target;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ConnectionTimeoutTest extends TestCase
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
            // Prevent .env PBS credentials from enabling the PBS collector in tests
            // that are not testing PBS behaviour; would cause an unexpected second warning.
            'watchtower.pbs.base_url' => null,
            'watchtower.pbs.token_id' => null,
            'watchtower.pbs.token_secret' => null,
        ]);
    }

    public function test_connection_exception_is_caught_and_scoped_checks_marked_unknown(): void
    {
        // Pre-create some in-scope targets with existing check rows.
        $pve = Target::factory()->node()->create(['name' => 'pve']);
        $webApp = Target::factory()->create(['type' => TargetType::Lxc, 'name' => 'web-app', 'node' => 'pve']);

        Check::factory()->for($pve)->up()->create();
        Check::factory()->for($webApp)->up()->create();

        Http::fake(fn () => throw new ConnectionException('timed out'));

        Log::spy();

        // Command must still exit successfully — timeout is a handled failure path
        $this->artisan('collect:run')->assertSuccessful();

        // Scoped checks must be Unknown
        $this->assertSame(TargetStatus::Unknown, $pve->fresh()->check->status);
        $this->assertSame(TargetStatus::Unknown, $webApp->fresh()->check->status);

        // A warning must have been logged
        Log::shouldHaveReceived('warning')->once();
    }
}
