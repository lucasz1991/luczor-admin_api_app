<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\ClaudeAccessService;
use App\Services\ClaudeProfileInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Claude setup belongs to the account, so every device of a user starts configured. */
class ClaudeAccountSetupTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'fake-personal-claude-key-3333';

    private const OPTIONS = '/api/v1/provider-keys/anthropic/options';

    protected function setUp(): void
    {
        parent::setUp();
        app(ClaudeProfileInstaller::class)->install();
    }

    public function test_a_second_device_of_the_same_user_receives_the_stored_setup_at_bootstrap(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        $first = $this->device($user);
        $second = $this->device($user);
        ProviderCredential::create([
            'user_id' => $user->id, 'provider' => 'anthropic', 'label' => 'Persönlicher Claude-Zugang',
            'api_key' => self::KEY, 'request_format' => 'messages', 'active' => true,
        ]);

        $this->withHeader('X-Api-Key', $first)->putJson(self::OPTIONS, ['preferred_model' => 'claude-sonnet-5-5'])
            ->assertOk()->assertJsonPath('preferred_model', 'claude-sonnet-5-5');
        $this->withHeader('X-Api-Key', $first)->putJson(self::OPTIONS, ['provider_fallback' => true])
            ->assertOk()->assertJsonPath('provider_fallback', true)->assertJsonPath('preferred_model', 'claude-sonnet-5-5');

        $response = $this->withHeader('X-Api-Key', $second)->getJson('/api/v1/bootstrap')->assertOk()
            ->assertJsonPath('routing.main_model.access', [
                'connected' => true,
                'source' => 'user',
                'provider_fallback' => true,
                'preferred_model' => 'claude-sonnet-5-5',
            ]);
        // The setup never carries key material, not even masked.
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertStringNotContainsString(substr(self::KEY, -4), json_encode($response->json('routing.main_model')));
    }

    public function test_the_setup_is_strictly_per_account(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        $other = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        ProviderCredential::create([
            'user_id' => $owner->id, 'provider' => 'anthropic', 'label' => 'Persönlicher Claude-Zugang',
            'api_key' => self::KEY, 'request_format' => 'messages', 'active' => true,
        ]);
        $this->withHeader('X-Api-Key', $this->device($owner))
            ->putJson(self::OPTIONS, ['preferred_model' => 'claude-haiku-5-5'])->assertOk();

        $this->withHeader('X-Api-Key', $this->device($other))->getJson('/api/v1/bootstrap')->assertOk()
            ->assertJsonPath('routing.main_model.access', [
                'connected' => false,
                'source' => null,
                'provider_fallback' => false,
                'preferred_model' => null,
            ]);
    }

    public function test_only_offered_models_can_be_stored_and_a_withdrawn_model_is_not_handed_out(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        $token = $this->device($user);

        // Fable is installed but hidden from the composer.
        $this->withHeader('X-Api-Key', $token)->putJson(self::OPTIONS, ['preferred_model' => 'claude-fable-5-2'])
            ->assertStatus(422)->assertJsonPath('error_code', 'claude_model_not_allowed');
        $this->withHeader('X-Api-Key', $token)->putJson(self::OPTIONS, ['preferred_model' => 'no such model'])
            ->assertStatus(422);
        $this->withHeader('X-Api-Key', $token)->putJson(self::OPTIONS, [])->assertStatus(422);

        $claude = app(ClaudeAccessService::class);
        $claude->setPreferredModel($user->id, 'claude-fable-5-2');
        $this->assertNull($claude->preferredModel($user->id));

        $this->withHeader('X-Api-Key', $token)->putJson(self::OPTIONS, ['preferred_model' => 'claude-opus-5-5'])->assertOk();
        $this->withHeader('X-Api-Key', $token)->putJson(self::OPTIONS, ['preferred_model' => null])
            ->assertOk()->assertJsonPath('preferred_model', null);
    }

    private function device(User $user): string
    {
        return ApiKey::mint([
            'user_id' => $user->id, 'name' => 'Desktop', 'abilities' => ['settings.read', 'provider.keys'], 'active' => true,
        ])['plain'];
    }
}
