<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_responds_with_the_watchtower_fingerprint_without_auth(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertSuccessful()
            ->assertExactJson([
                'service' => 'watchtower-hub',
            ]);
    }

    public function test_get_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/user')
            ->assertSuccessful()
            ->assertJsonPaths([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);
    }

    public function test_not_authenticated(): void
    {
        $this->getJson('/api/v1/user')
            ->assertUnauthorized();
    }
}
