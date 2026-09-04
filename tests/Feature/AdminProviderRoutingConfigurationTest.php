<?php

namespace Tests\Feature;

use App\Models\NetworkPolicy;
use App\Models\ProviderCredential;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProviderRoutingConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_wire_format_is_persisted_and_incompatible_pairs_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

        $this->actingAs($admin)->post(route('dashboard.provider-credentials.store'), [
            'provider' => 'openai',
            'label' => 'OpenAI default wire',
            'api_key' => 'secret',
            'base_url' => 'https://api.openai.com/v1',
        ])->assertRedirect();
        $this->assertDatabaseHas('provider_credentials', [
            'provider' => 'openai',
            'label' => 'OpenAI default wire',
            'request_format' => 'responses',
        ]);

        $this->actingAs($admin)->from('/dashboard')->post(route('dashboard.provider-credentials.store'), [
            'provider' => 'anthropic',
            'label' => 'Wrong wire',
            'api_key' => 'secret',
            'base_url' => 'https://api.anthropic.com',
            'request_format' => 'chat_completions',
        ])->assertRedirect('/dashboard')->assertSessionHasErrors('request_format');
        $this->assertDatabaseMissing('provider_credentials', ['label' => 'Wrong wire']);
    }

    public function test_model_profile_requires_an_explicit_active_same_provider_credential(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $credential = ProviderCredential::create([
            'provider' => 'openrouter',
            'label' => 'OpenRouter',
            'api_key' => 'secret',
            'request_format' => 'chat_completions',
            'active' => true,
        ]);
        $profile = [
            'name' => 'Explicit profile',
            'provider' => 'openai',
            'provider_credential_id' => $credential->id,
            'model_id' => 'test/model',
            'temperature' => 0.1,
            'max_tokens' => 100,
            'purpose' => 'chat',
        ];

        $this->actingAs($admin)->from('/dashboard')->post(route('dashboard.model-profiles.store'), $profile)
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors('provider_credential_id');

        $profile['provider'] = 'openrouter';
        $this->actingAs($admin)->post(route('dashboard.model-profiles.store'), $profile)->assertRedirect();
        $this->assertDatabaseHas('model_profiles', [
            'slug' => 'explicit-profile',
            'provider' => 'openrouter',
            'provider_credential_id' => $credential->id,
        ]);
    }

    public function test_admin_created_network_policy_gets_an_explicit_retry_contract(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

        $this->actingAs($admin)->post(route('dashboard.network-policies.store'), [
            'key' => 'proxy.admin.strict',
            'name' => 'Admin strict',
            'connect_timeout_ms' => 1000,
            'request_timeout_ms' => 5000,
            'max_attempts' => 2,
            'backoff_ms' => 0,
            'max_input_tokens' => 1000,
            'max_output_tokens' => 100,
        ])->assertRedirect();

        $policy = NetworkPolicy::query()->where('key', 'proxy.admin.strict')->firstOrFail();
        $this->assertSame(
            [0, 408, 409, 425, 429, 500, 502, 503, 504, 529],
            $policy->config['retry_statuses'],
        );
    }

    public function test_non_voice_seeded_use_cases_reference_the_seeded_network_policy(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['chat', 'coding', 'planner', 'verifier', 'vision'] as $slug) {
            $this->assertDatabaseHas('model_use_cases', [
                'slug' => $slug,
                'network_policy_key' => 'proxy.openrouter.default',
                'routing_strategy' => 'manual',
                'policy_version' => 1,
            ]);
        }
        $policy = NetworkPolicy::query()->where('key', 'proxy.openrouter.default')->firstOrFail();
        $this->assertSame(
            [0, 408, 409, 425, 429, 500, 502, 503, 504, 529],
            $policy->config['retry_statuses'],
        );
    }
}
