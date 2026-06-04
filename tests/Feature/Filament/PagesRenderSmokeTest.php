<?php

namespace Tests\Feature\Filament;

use App\Models\Target;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesRenderSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_admin_screens_render_for_authenticated_operator(): void
    {
        $user = User::factory()->create();
        Target::factory()->create();

        $this->actingAs($user);

        $this->get('/admin/settings')->assertSuccessful();
        $this->get('/admin/tokens')->assertSuccessful();
        $this->get('/admin/targets')->assertSuccessful();
        $this->get('/admin/targets/create')->assertSuccessful();
        $this->get('/admin/rules')->assertSuccessful();
        $this->get('/admin/rules/create')->assertSuccessful();
    }
}
