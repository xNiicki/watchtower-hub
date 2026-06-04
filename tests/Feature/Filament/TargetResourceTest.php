<?php

namespace Tests\Feature\Filament;

use App\Enums\TargetType;
use App\Filament\Resources\Targets\Pages\CreateTarget;
use App\Filament\Resources\Targets\Pages\EditTarget;
use App\Filament\Resources\Targets\Pages\ListTargets;
use App\Models\Target;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TargetResourceTest extends TestCase
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
        $this->get('/admin/targets')->assertRedirect('/admin/login');
    }

    public function test_list_page_renders(): void
    {
        $this->actingAsOperator();
        Target::factory()->create();

        Livewire::test(ListTargets::class)->assertOk();
    }

    public function test_create_service_target_persists(): void
    {
        $this->actingAsOperator();

        Livewire::test(CreateTarget::class)
            ->fillForm([
                'type' => TargetType::Service->value,
                'name' => 'My API',
                'enabled' => true,
                'check_config' => [
                    'url' => 'https://api.example.com/health',
                    'timeout_ms' => 3000,
                    'verify_tls' => true,
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $target = Target::query()->where('name', 'My API')->firstOrFail();

        $this->assertSame(TargetType::Service, $target->type);
        $this->assertSame('https://api.example.com/health', $target->check_config['url']);
    }

    public function test_toggling_enabled_works(): void
    {
        $this->actingAsOperator();

        $target = Target::factory()->create(['enabled' => true]);

        Livewire::test(EditTarget::class, ['record' => $target->getRouteKey()])
            ->fillForm(['enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($target->fresh()->enabled);
    }

    public function test_infra_target_keeps_type_and_name_read_only(): void
    {
        $this->actingAsOperator();

        $target = Target::factory()->create([
            'type' => TargetType::Lxc,
            'name' => 'discovered-lxc',
        ]);

        // The edit form loads; type/name are disabled so they are not editable,
        // but enabled can still be toggled.
        Livewire::test(EditTarget::class, ['record' => $target->getRouteKey()])
            ->assertOk()
            ->fillForm(['enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $target->fresh();
        $this->assertSame(TargetType::Lxc, $fresh->type);
        $this->assertSame('discovered-lxc', $fresh->name);
        $this->assertFalse($fresh->enabled);
    }
}
