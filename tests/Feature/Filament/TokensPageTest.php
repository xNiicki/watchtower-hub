<?php

namespace Tests\Feature\Filament;

use App\Enums\TokenAbility;
use App\Filament\Pages\Tokens;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

class TokensPageTest extends TestCase
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
        $this->get('/admin/tokens')->assertRedirect('/admin/login');
    }

    public function test_page_renders(): void
    {
        $this->actingAsOperator();

        Livewire::test(Tokens::class)->assertOk();
    }

    public function test_create_action_mints_a_token_with_correct_abilities_and_surfaces_plaintext_once(): void
    {
        $operator = $this->actingAsOperator();

        $component = Livewire::test(Tokens::class)
            ->callAction('createToken', ['name' => 'My iPhone'])
            ->assertHasNoErrors()
            ->assertNotified();

        $token = PersonalAccessToken::query()->where('name', 'My iPhone')->firstOrFail();

        $this->assertSame($operator->getMorphClass(), $token->tokenable_type);
        $this->assertSame((int) $operator->getKey(), (int) $token->tokenable_id);
        $this->assertEqualsCanonicalizing(TokenAbility::values(), $token->abilities);

        // Plaintext is surfaced exactly once via the component property.
        $plain = $component->get('plainTextToken');
        $this->assertNotNull($plain);
        $this->assertStringContainsString('|', $plain);

        // Dismissing clears it so it cannot be shown again.
        $component->call('dismissPlainTextToken')
            ->assertSet('plainTextToken', null);
    }

    public function test_revoke_action_deletes_a_token(): void
    {
        $operator = $this->actingAsOperator();

        $token = $operator->createToken('to-be-revoked', TokenAbility::values())->accessToken;

        Livewire::test(Tokens::class)
            ->callTableAction('revoke', $token)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->getKey()]);
    }
}
