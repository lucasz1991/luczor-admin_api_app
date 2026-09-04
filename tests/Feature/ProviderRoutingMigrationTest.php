<?php

namespace Tests\Feature;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\NetworkPolicy;
use App\Models\ProviderCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderRoutingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hardening_only_migrates_explicit_compatible_legacy_wire_metadata_idempotently(): void
    {
        $openRouter = ProviderCredential::create([
            'provider' => 'openrouter',
            'label' => 'Legacy OpenRouter',
            'api_key' => 'secret',
            'request_format' => null,
            'active' => true,
            'meta' => ['wire' => 'chat_completions'],
        ]);
        $openRouterProfile = $this->profile('openrouter', 'legacy/openrouter');

        $missingWire = ProviderCredential::create([
            'provider' => 'anthropic', 'label' => 'Anthropic no wire', 'api_key' => 'a',
            'request_format' => null, 'active' => true, 'meta' => [],
        ]);
        $incompatibleWire = ProviderCredential::create([
            'provider' => 'openrouter', 'label' => 'Incompatible wire', 'api_key' => 'b',
            'request_format' => null, 'active' => true, 'meta' => ['wire' => 'responses'],
        ]);

        NetworkPolicy::create([
            'key' => 'proxy.openrouter.default', 'name' => 'Legacy default', 'status' => 'active',
            'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000, 'max_attempts' => 2,
            'backoff_ms' => 0, 'config' => ['transport' => 'preserved'],
        ]);
        NetworkPolicy::create([
            'key' => 'proxy.custom', 'name' => 'Custom', 'status' => 'active',
            'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000, 'max_attempts' => 2,
            'backoff_ms' => 0, 'config' => ['retry_statuses' => [418], 'custom' => true],
        ]);
        NetworkPolicy::create([
            'key' => 'proxy.malformed', 'name' => 'Malformed', 'status' => 'active',
            'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000, 'max_attempts' => 2,
            'backoff_ms' => 0, 'config' => ['retry_statuses' => [999], 'custom' => 'kept'],
        ]);
        $nullPolicy = ModelUseCase::create([
            'name' => 'Chat', 'slug' => 'chat', 'active' => true, 'network_policy_key' => null,
        ]);
        $emptyPolicy = ModelUseCase::create([
            'name' => 'Planner', 'slug' => 'planner', 'active' => true, 'network_policy_key' => '',
        ]);

        $migration = require database_path('migrations/2026_08_30_000001_harden_provider_routing_contracts.php');
        $migration->up();
        $migration->up();

        $this->assertSame('chat_completions', $openRouter->fresh()->request_format);
        $this->assertNull($missingWire->fresh()->request_format);
        $this->assertNull($incompatibleWire->fresh()->request_format);
        $this->assertNull($openRouterProfile->fresh()->provider_credential_id);
        $this->assertNull($nullPolicy->fresh()->network_policy_key);
        $this->assertSame('', $emptyPolicy->fresh()->network_policy_key);

        $defaultConfig = NetworkPolicy::query()->where('key', 'proxy.openrouter.default')->firstOrFail()->config;
        $this->assertSame('preserved', $defaultConfig['transport']);
        $this->assertArrayNotHasKey('retry_statuses', $defaultConfig);
        $customConfig = NetworkPolicy::query()->where('key', 'proxy.custom')->firstOrFail()->config;
        $this->assertSame([418], $customConfig['retry_statuses']);
        $this->assertTrue($customConfig['custom']);
        $malformedConfig = NetworkPolicy::query()->where('key', 'proxy.malformed')->firstOrFail()->config;
        $this->assertSame('kept', $malformedConfig['custom']);
        $this->assertSame([999], $malformedConfig['retry_statuses']);
    }

    private function profile(string $provider, string $modelId): ModelProfile
    {
        return ModelProfile::create([
            'name' => $modelId,
            'slug' => str_replace('/', '-', $modelId),
            'provider' => $provider,
            'provider_credential_id' => null,
            'model_id' => $modelId,
            'temperature' => 0.1,
            'max_tokens' => 100,
            'purpose' => 'chat',
            'active' => true,
        ]);
    }
}
