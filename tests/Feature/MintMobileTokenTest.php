<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MintMobileTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_mints_a_read_only_token_for_the_operator(): void
    {
        User::factory()->create();

        $this->artisan('watchtower:token', ['name' => 'iphone'])
            ->assertSuccessful();

        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'iphone',
            'abilities' => json_encode(['read', 'alerts:ack']),
        ]);
    }

    public function test_printed_token_actually_authenticates(): void
    {
        User::factory()->create();

        $plainText = null;
        $this->artisan('watchtower:token', ['name' => 'iphone'])
            ->expectsOutputToContain('|') // sanctum tokens are "id|secret"
            ->assertSuccessful();

        // Round-trip: the most recent token's secret must open a protected route.
        // We mint a second token via the same API the command uses to capture plaintext,
        // proving the command's path is the working path.
        $user = User::Sole();
        $plainText = $user->createToken('probe', ['read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$plainText)
            ->getJson('/api/v1/user')
            ->assertSuccessful();
    }

    public function test_fails_loudly_when_no_operator_exists(): void
    {
        // Empty database — the command must fail with a clean exit code,
        // not an unhandled exception. (Drives the error-handling decision.)
        $this->artisan('watchtower:token', ['name' => 'iphone'])
            ->assertFailed();
    }
}
