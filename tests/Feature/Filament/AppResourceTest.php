<?php

namespace Tests\Feature\Filament;

use App\Enums\TokenAbility;
use App\Filament\Resources\Apps\Pages\CreateApp;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Models\MonitoredApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppResourceTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOperator(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get('/admin/apps')->assertRedirect('/admin/login');
    }

    public function test_create_app_persists(): void
    {
        $this->actingAsOperator();

        Livewire::test(CreateApp::class)
            ->fillForm(['name' => 'Booking', 'slug' => 'booking'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('apps', ['slug' => 'booking', 'name' => 'Booking']);
    }

    public function test_mint_token_action_issues_ingest_token_and_revokes_old(): void
    {
        $this->actingAsOperator();
        $app = MonitoredApp::factory()->create();
        $app->createToken('old', [TokenAbility::Ingest->value]); // pre-existing

        Livewire::test(ListApps::class)
            ->callTableAction('mintToken', $app)
            ->assertNotified('Ingest token minted');

        // Exactly one ingest token remains (old revoked, new minted).
        $this->assertSame(1, $app->fresh()->tokens()->count());
        $this->assertTrue($app->fresh()->tokens()->first()->can('ingest'));
    }
}
