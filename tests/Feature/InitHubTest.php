<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
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

    /**
     * Regression guard: the init token must never carry the ingest ability.
     * Phones may not push telemetry — only the satellite ingest endpoint should.
     */
    public function test_init_token_has_read_but_not_ingest(): void
    {
        $this->artisan('watchtower:init')->assertSuccessful();

        $pat = PersonalAccessToken::query()->where('name', 'mobile')->firstOrFail();

        $this->assertTrue($pat->can(TokenAbility::Read->value), 'init token must have the read ability');
        $this->assertFalse($pat->can(TokenAbility::Ingest->value), 'init token must NOT have the ingest ability — phones cannot push telemetry');
        $this->assertEqualsCanonicalizing(TokenAbility::mobile(), $pat->abilities);
    }
}
