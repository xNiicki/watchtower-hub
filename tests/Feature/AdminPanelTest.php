<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $this->get('/admin/login')
            ->assertSuccessful()
            ->assertSee('Sign in');
    }

    public function test_unauthenticated_request_to_panel_is_redirected_to_login(): void
    {
        $this->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_authenticated_user_can_reach_the_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertSuccessful();
    }

    public function test_panel_uses_the_web_guard_and_does_not_authenticate_via_sanctum_tokens(): void
    {
        // A Sanctum personal-access token (mobile API auth) must NOT grant
        // access to the session-guarded web panel.
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->get('/admin')
            ->assertRedirect('/admin/login');
    }
}
