<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_without_api_key_is_rejected(): void
    {
        $this->getJson('/api/v1/bootstrap')->assertStatus(401);
    }

    public function test_invalid_api_key_is_rejected(): void
    {
        $this->withHeader('X-Api-Key', 'invalid')->getJson('/api/v1/bootstrap')->assertStatus(401);
    }

    public function test_missing_ability_is_forbidden(): void
    {
        $user = User::factory()->create();
        $minted = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Test Device',
            'abilities' => ['sync.read'],
            'active' => true,
        ]);

        $this->withHeader('X-Api-Key', $minted['plain'])->getJson('/api/v1/bootstrap')->assertStatus(403);
    }

    public function test_valid_api_key_can_read_bootstrap(): void
    {
        $user = User::factory()->create();
        $minted = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Test Device',
            'abilities' => ['settings.read'],
            'active' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$minted['plain'])
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }
}
