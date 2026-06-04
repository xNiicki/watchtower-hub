<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenAbilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_set_is_read_and_ack_only(): void
    {
        $this->assertSame(['read', 'alerts:ack'], TokenAbility::mobile());
    }

    public function test_ingest_is_not_in_the_mobile_set(): void
    {
        $this->assertNotContains(TokenAbility::Ingest->value, TokenAbility::mobile());
    }

    public function test_minted_mobile_token_cannot_ingest(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone', TokenAbility::mobile());

        $this->assertTrue($token->accessToken->can('read'));
        $this->assertFalse($token->accessToken->can('ingest'));
    }
}
