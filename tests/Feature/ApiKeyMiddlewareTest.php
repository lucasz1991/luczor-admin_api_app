<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Carbon\Carbon;
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

    public function test_last_used_timestamp_is_touched_at_most_once_per_ten_minutes(): void
    {
        Carbon::setTestNow('2026-08-22T12:00:00Z');

        try {
            $user = User::factory()->create();
            $minted = ApiKey::mint([
                'user_id' => $user->id,
                'name' => 'Polling Device',
                'abilities' => ['settings.read'],
                'active' => true,
            ]);
            /** @var ApiKey $apiKey */
            $apiKey = $minted['model'];

            $this->withHeader('X-Api-Key', $minted['plain'])
                ->getJson('/api/v1/bootstrap')
                ->assertOk();
            $this->assertTrue($apiKey->fresh()->last_used_at->equalTo(now()));

            $recent = now()->subMinutes(5);
            $apiKey->forceFill(['last_used_at' => $recent])->save();
            $this->withHeader('X-Api-Key', $minted['plain'])
                ->getJson('/api/v1/bootstrap')
                ->assertOk();
            $this->assertTrue($apiKey->fresh()->last_used_at->equalTo($recent));

            $stale = now()->subMinutes(11);
            $apiKey->forceFill(['last_used_at' => $stale])->save();
            $this->withHeader('X-Api-Key', $minted['plain'])
                ->getJson('/api/v1/bootstrap')
                ->assertOk();
            $this->assertTrue($apiKey->fresh()->last_used_at->equalTo(now()));
        } finally {
            Carbon::setTestNow();
        }
    }
}
