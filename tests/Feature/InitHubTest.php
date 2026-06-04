<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_boot_creates_operator_and_prints_a_token(): void
    {
        $this->assertDatabaseCount('users', 0);

        $this->artisan('watchtower:init')
            ->assertSuccessful()
            ->expectsOutputToContain('MOBILE API TOKEN');

        $this->assertDatabaseHas('users', ['email' => 'admin@watchtower.local']);
        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'mobile',
            'abilities' => json_encode(['read', 'alerts:ack']),
        ]);
    }

    public function test_is_idempotent_and_does_not_mint_a_second_token(): void
    {
        $this->artisan('watchtower:init')->assertSuccessful();
        $this->artisan('watchtower:init')
            ->assertSuccessful()
            ->expectsOutputToContain('already exists');

        $this->assertDatabaseCount('users', 1);
        $this->assertSame(1, User::sole()->tokens()->where('name', 'mobile')->count());
    }

    public function test_respects_the_configured_operator_email(): void
    {
        config(['watchtower.operator.email' => 'nick@example.com']);

        $this->artisan('watchtower:init')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'nick@example.com']);
    }
}
