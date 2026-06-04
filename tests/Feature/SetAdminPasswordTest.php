<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SetAdminPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_the_password_on_the_existing_operator_so_they_can_authenticate(): void
    {
        $this->artisan('watchtower:init')->assertSuccessful();
        $email = (string) config('watchtower.operator.email');

        $this->artisan('watchtower:admin', ['--password' => 'sup3r-secret'])
            ->assertSuccessful();

        $this->assertTrue(
            Auth::attempt(['email' => $email, 'password' => 'sup3r-secret']),
            'The operator should be able to authenticate with the newly set password.',
        );
        $this->assertDatabaseCount('users', 1);
    }

    public function test_targets_the_email_argument(): void
    {
        $user = User::factory()->create(['email' => 'someone@example.com']);

        $this->artisan('watchtower:admin', [
            'email' => 'someone@example.com',
            '--password' => 'another-secret',
        ])->assertSuccessful();

        $this->assertTrue(Auth::attempt([
            'email' => 'someone@example.com',
            'password' => 'another-secret',
        ]));
        $this->assertSame($user->id, Auth::id());
    }

    public function test_is_idempotent_and_safe_to_re_run(): void
    {
        $this->artisan('watchtower:init')->assertSuccessful();
        $email = (string) config('watchtower.operator.email');

        $this->artisan('watchtower:admin', ['--password' => 'first-password'])->assertSuccessful();
        $this->artisan('watchtower:admin', ['--password' => 'second-password'])->assertSuccessful();

        // Only the most recent password is valid; still exactly one user.
        $this->assertFalse(Auth::attempt(['email' => $email, 'password' => 'first-password']));
        $this->assertTrue(Auth::attempt(['email' => $email, 'password' => 'second-password']));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_rejects_a_password_shorter_than_the_minimum(): void
    {
        $this->artisan('watchtower:init')->assertSuccessful();

        $this->artisan('watchtower:admin', ['--password' => 'short'])
            ->assertFailed();
    }
}
