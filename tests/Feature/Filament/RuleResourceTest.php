<?php

namespace Tests\Feature\Filament;

use App\Enums\AlertTier;
use App\Filament\Resources\Rules\Pages\CreateRule;
use App\Filament\Resources\Rules\Pages\ListRules;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RuleResourceTest extends TestCase
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
        $this->get('/admin/rules')->assertRedirect('/admin/login');
    }

    public function test_list_page_renders(): void
    {
        $this->actingAsOperator();
        Rule::factory()->create();

        Livewire::test(ListRules::class)->assertOk();
    }

    public function test_create_rule_persists_with_params(): void
    {
        $this->actingAsOperator();

        Livewire::test(CreateRule::class)
            ->fillForm([
                'key' => 'node-cpu-high',
                'condition_type' => 'metric_threshold',
                'params' => [
                    'metric' => 'cpu',
                    'operator' => '>',
                    'threshold' => '90',
                ],
                'duration_seconds' => 300,
                'tier' => AlertTier::Warning->value,
                'enabled' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = Rule::query()->where('key', 'node-cpu-high')->firstOrFail();

        $this->assertSame('metric_threshold', $rule->condition_type);
        $this->assertSame(300, $rule->duration_seconds);
        $this->assertSame(AlertTier::Warning, $rule->tier);
        $this->assertSame('cpu', $rule->params['metric']);
        $this->assertSame('90', $rule->params['threshold']);
    }
}
