<?php

namespace Tests\Feature;

use App\Exceptions\RoutingPolicyException;
use App\Models\ApiKey;
use App\Models\LlmAttempt;
use App\Models\LlmRun;
use App\Models\ModelProfile;
use App\Models\ModelRanking;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\NetworkPolicy;
use App\Models\ProviderCredential;
use App\Models\ProviderPriceSnapshot;
use App\Models\User;
use App\Services\ProviderHttpClientFactory;
use App\Services\ProviderPolicyService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FailClosedProviderRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_capability_rejects_before_any_provider_client_is_created(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $profile = $this->profile('openrouter', 'test/text-only', $credential, ['chat']);
        $this->price($profile);
        $this->useCase('vision', [$profile]);
        $this->policy('proxy.strict');
        $this->expectNoProviderClient();

        $this->proxy($token, ['task_type' => 'vision.describe'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'routing_capability_unavailable')
            ->assertHeader('X-Luczor-Routing-Reason', 'routing_capability_unavailable');
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_missing_current_usd_price_rejects_even_without_a_cost_cap(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $profile = $this->profile('openrouter', 'test/unpriced', $credential);
        $this->useCase('chat', [$profile]);
        $this->policy('proxy.strict');
        $this->expectNoProviderClient();

        $this->proxy($token)
            ->assertStatus(503)
            ->assertJsonPath('code', 'routing_price_unavailable');
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_large_assistant_tool_arguments_count_toward_the_input_budget_before_dispatch(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $profile = $this->profile('openrouter', 'test/tool-budget', $credential);
        $this->price($profile);
        $this->useCase('chat', [$profile]);
        $this->policy('proxy.strict')->update(['max_input_tokens' => 100]);
        $this->expectNoProviderClient();

        $this->proxy($token, ['messages' => [[
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => 'call-budget-1',
                'type' => 'function',
                'function' => [
                    'name' => 'bounded_action',
                    'arguments' => json_encode(['payload' => str_repeat('x', 4000)], JSON_THROW_ON_ERROR),
                ],
            ]],
        ]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'routing_input_budget_exceeded');

        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_multibyte_unicode_uses_a_conservative_utf8_byte_budget_before_dispatch(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $profile = $this->profile('openrouter', 'test/unicode-budget', $credential);
        $this->price($profile);
        $this->useCase('chat', [$profile]);
        $this->policy('proxy.strict')->update(['max_input_tokens' => 250]);
        $this->expectNoProviderClient();

        $this->proxy($token, ['messages' => [[
            'role' => 'user',
            'content' => str_repeat('🙂', 80),
        ]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'routing_input_budget_exceeded');

        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_unencodable_messages_and_tools_wire_fails_closed(): void
    {
        try {
            app(ProviderPolicyService::class)->estimatedInputTokens([
                'messages' => [['role' => 'user', 'content' => "\xB1\x31"]],
                'tools' => [],
            ]);
            $this->fail('Invalid UTF-8 must not receive a token estimate.');
        } catch (RoutingPolicyException $exception) {
            $this->assertSame('routing_input_unencodable', $exception->reasonCode);
            $this->assertSame(422, $exception->httpStatus);
        }
    }

    public function test_admin_route_endpoint_is_a_strict_non_executable_preview_without_literal_fallbacks(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $minted = ApiKey::mint([
            'user_id' => $admin->id,
            'name' => 'Admin preview',
            'abilities' => ['brain.read'],
            'active' => true,
        ]);
        $credential = $this->credential('openrouter', 'chat_completions');
        $profile = $this->profile('openrouter', 'test/admin-preview', $credential);
        $this->price($profile);
        $this->useCase('chat', [$profile], policyVersion: 9);
        $this->policy('proxy.strict');
        $this->expectNoProviderClient();

        $this->withHeader('X-Api-Key', $minted['plain'])
            ->postJson('/api/v1/llm/route', ['task_type' => 'chat.general'])
            ->assertOk()
            ->assertJsonPath('model_id', $profile->model_id)
            ->assertJsonPath('provider', 'openrouter')
            ->assertJsonPath('routing_policy_version', 9)
            ->assertJsonPath('network_policy_key', 'proxy.strict')
            ->assertJsonPath('executable', false)
            ->assertJsonPath('preview_only', true)
            ->assertJsonPath('provider_request_dispatched', false);

        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_non_retriable_provider_redirect_is_rejected_without_exposing_the_location(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $profile = $this->profile('openrouter', 'test/redirect', $credential);
        $this->price($profile);
        $this->useCase('chat', [$profile]);
        $this->policy('proxy.strict');

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(
            302,
            ['Location' => 'https://attacker.invalid/capture'],
            'redirect body',
        ));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $response = $this->proxy($token)
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_redirect_rejected')
            ->assertJsonPath('provider_status', 302);
        $response->assertHeaderMissing('Location');
        $this->assertStringNotContainsString('attacker.invalid', $response->getContent());
        $this->assertDatabaseHas('llm_attempts', [
            'http_status' => 302,
            'status' => 'failed',
            'error_type' => 'provider_redirect_rejected',
        ]);
    }

    public function test_informational_provider_status_cannot_become_a_winner(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $profile = $this->profile('openrouter', 'test/informational-status', $credential);
        $this->price($profile);
        $this->useCase('chat', [$profile]);
        $this->policy('proxy.strict');

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(103, [], 'not-final'));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $response = $this->proxy($token)
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_informational_status_rejected')
            ->assertJsonPath('provider_status', 103);
        $this->assertStringNotContainsString('not-final', $response->getContent());
        $this->assertDatabaseHas('llm_attempts', [
            'http_status' => 103,
            'status' => 'failed',
            'error_type' => 'provider_informational_status_rejected',
        ]);
    }

    public function test_redirect_status_cannot_be_enabled_for_retry(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $profile = $this->profile('openrouter', 'test/redirect-retry-policy', $credential);
        $this->price($profile);
        $this->useCase('chat', [$profile], maxAttempts: 2);
        $this->policy('proxy.strict', maxAttempts: 2, retryStatuses: [302]);
        $this->expectNoProviderClient();

        $this->proxy($token)
            ->assertStatus(503)
            ->assertJsonPath('code', 'routing_network_policy_retry_statuses_invalid');
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_use_case_must_name_an_existing_network_policy_and_never_uses_the_legacy_default(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $profile = $this->profile('openrouter', 'test/network', $credential);
        $this->price($profile);
        $this->useCase('chat', [$profile], networkPolicyKey: null);
        $this->policy('proxy.openrouter.default');
        $this->expectNoProviderClient();

        $this->proxy($token)
            ->assertStatus(503)
            ->assertJsonPath('code', 'routing_network_policy_unavailable');
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_exact_use_case_network_policy_is_used_and_reported(): void
    {
        [$user, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $profile = $this->profile('openrouter', 'test/exact-policy', $credential);
        $this->price($profile);
        $this->useCase('chat', [$profile], networkPolicyKey: 'proxy.custom.strict', policyVersion: 7);
        $this->policy('proxy.openrouter.default');
        $this->policy('proxy.custom.strict');

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn($this->successResponse());
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->withArgs(function (NetworkPolicy $policy): bool {
            $this->assertSame('proxy.custom.strict', $policy->key);

            return true;
        })->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $this->proxy($token)
            ->assertOk()
            ->assertHeader('X-Luczor-Network-Policy', 'proxy.custom.strict')
            ->assertHeader('X-Luczor-Routing-Policy-Version', '7')
            ->assertHeader('X-Luczor-Routing-Class', 'external');
        $this->assertDatabaseHas('llm_runs', [
            'user_id' => $user->id,
            'network_policy_id' => 'proxy.custom.strict',
            'routing_policy_version' => 7,
            'routing_reason_code' => 'external_policy_manual',
        ]);
    }

    public function test_credential_provider_and_request_format_must_both_match_explicitly(): void
    {
        [, $providerMismatchToken] = $this->deviceToken();
        $openAi = $this->credential('openai', 'responses');
        $providerMismatch = $this->profile('openrouter', 'test/provider-mismatch', $openAi);
        $this->price($providerMismatch);
        $this->useCase('chat', [$providerMismatch]);
        $this->policy('proxy.strict');
        $this->expectNoProviderClient();

        $this->proxy($providerMismatchToken)
            ->assertStatus(503)
            ->assertJsonPath('code', 'routing_credential_incompatible');

        ModelUseCaseEntry::query()->delete();
        ModelUseCase::query()->delete();
        NetworkPolicy::query()->delete();
        ModelProfile::query()->delete();
        ProviderCredential::query()->delete();
        ProviderPriceSnapshot::query()->delete();

        [, $legacyWireToken] = $this->deviceToken();
        $legacyOnly = ProviderCredential::create([
            'provider' => 'openrouter',
            'label' => 'Legacy meta only',
            'api_key' => 'test-secret',
            'request_format' => null,
            'active' => true,
            'meta' => ['wire' => 'chat_completions'],
        ]);
        $legacyProfile = $this->profile('openrouter', 'test/legacy-wire', $legacyOnly);
        $this->price($legacyProfile);
        $this->useCase('chat', [$legacyProfile]);
        $this->policy('proxy.strict');
        $this->expectNoProviderClient();

        $this->proxy($legacyWireToken)
            ->assertStatus(503)
            ->assertJsonPath('code', 'routing_credential_incompatible');
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_effective_attempts_are_the_minimum_of_use_case_network_and_candidates(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $profiles = [];
        foreach (['one', 'two', 'three'] as $suffix) {
            $profile = $this->profile('openrouter', 'test/'.$suffix, $credential);
            $this->price($profile);
            $profiles[] = $profile;
        }
        $this->useCase('chat', $profiles, maxAttempts: 3);
        $this->policy('proxy.strict', maxAttempts: 2);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->twice()->andReturn(
            new Response(503, [], 'sensitive-first-error'),
            new Response(503, [], 'sensitive-second-error'),
        );
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $response = $this->proxy($token)
            ->assertStatus(502)
            ->assertHeader('X-Luczor-Attempt-Limit', '2')
            ->assertJsonPath('code', 'provider_request_failed');
        $this->assertStringNotContainsString('sensitive-second-error', $response->getContent());
        $this->assertSame(2, LlmAttempt::count());
    }

    public function test_fallback_winner_replaces_the_initial_run_cost_estimate(): void
    {
        [$user, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $primary = $this->profile('openrouter', 'test/cheap-primary', $credential);
        $fallback = $this->profile('openrouter', 'test/costly-fallback', $credential);
        $this->price($primary, 1, 1);
        $this->price($fallback, 10, 20);
        $this->useCase('chat', [$primary, $fallback], maxAttempts: 2);
        $this->policy('proxy.strict', maxAttempts: 2);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->twice()->andReturn(
            new Response(503, [], 'retry'),
            $this->successResponse(),
        );
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $this->proxy($token)->assertOk()->assertHeader('X-Luczor-Model-Id', $fallback->model_id);
        $run = LlmRun::query()->where('user_id', $user->id)->firstOrFail();
        $attempts = $run->attempts()->orderBy('attempt_no')->get();
        $primaryEstimate = (float) $attempts[0]->routing_meta['estimated_cost_usd'];
        $fallbackEstimate = (float) $attempts[1]->routing_meta['estimated_cost_usd'];
        $this->assertNotSame($primaryEstimate, $fallbackEstimate);
        $this->assertEqualsWithDelta($fallbackEstimate, (float) $run->estimated_cost_usd, 0.00000001);
    }

    public function test_cost_cap_reserves_the_complete_possible_retry_ladder_before_dispatch(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $primary = $this->profile('openrouter', 'test/reserve-primary', $credential);
        $fallback = $this->profile('openrouter', 'test/reserve-fallback', $credential);
        $this->price($primary, 0, 1000);
        $this->price($fallback, 0, 1000);
        $useCase = $this->useCase('chat', [$primary, $fallback], maxAttempts: 2);
        $useCase->update(['max_cost_usd' => 0.15]);
        $this->policy('proxy.strict', maxAttempts: 2);
        $this->expectNoProviderClient();

        $this->proxy($token)
            ->assertStatus(422)
            ->assertJsonPath('code', 'routing_budget_exceeded');

        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_gateway_reprices_the_remaining_ladder_cumulatively_before_retry(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $primary = $this->profile('openrouter', 'test/reprice-primary', $credential);
        $fallback = $this->profile('openrouter', 'test/reprice-fallback', $credential);
        $this->price($primary, 0, 500);
        $this->price($fallback, 0, 500);
        $useCase = $this->useCase('chat', [$primary, $fallback], maxAttempts: 2);
        $useCase->update(['max_cost_usd' => 0.15]);
        $this->policy('proxy.strict', maxAttempts: 2);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturnUsing(function () use ($fallback): Response {
            ProviderPriceSnapshot::query()
                ->where('provider_id', $fallback->provider)
                ->where('model_id', $fallback->model_id)
                ->update(['valid_until' => now()->subSecond()]);
            $this->price($fallback, 0, 2000);

            return new Response(503, [], 'retry');
        });
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $this->proxy($token)
            ->assertStatus(422)
            ->assertJsonPath('code', 'routing_budget_exceeded');

        $this->assertSame(1, LlmAttempt::count());
        $this->assertDatabaseHas('llm_runs', [
            'status' => 'policy_rejected',
            'routing_reason_code' => 'routing_budget_exceeded',
            'attempt_count' => 1,
        ]);
    }

    public function test_ranked_strategy_changes_order_only_with_eligible_measured_profiles(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $adminFirst = $this->profile('openrouter', 'test/admin-first', $credential);
        $rankedFirst = $this->profile('openrouter', 'test/ranked-first', $credential);
        $this->price($adminFirst);
        $this->price($rankedFirst);
        $this->useCase('chat', [$adminFirst, $rankedFirst], strategy: 'ranked');
        $this->policy('proxy.strict');
        ModelRanking::create([
            'task_type' => 'chat.general',
            'model_id' => $adminFirst->model_id,
            'model_profile_id' => $adminFirst->id,
            'provider_id' => 'openrouter',
            'sample_count' => 10,
            'score' => 0.2,
        ]);
        ModelRanking::create([
            'task_type' => 'chat.general',
            'model_id' => $rankedFirst->model_id,
            'model_profile_id' => $rankedFirst->id,
            'provider_id' => 'openrouter',
            'sample_count' => 10,
            'score' => 0.9,
        ]);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function (string $method, string $url, array $options) use ($rankedFirst): bool {
            $this->assertSame($rankedFirst->model_id, $options['json']['model']);

            return true;
        })->andReturn($this->successResponse());
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $this->proxy($token)
            ->assertOk()
            ->assertHeader('X-Luczor-Selection-Source', 'admin_policy_ranked')
            ->assertHeader('X-Luczor-Routing-Reason', 'external_policy_ranked')
            ->assertHeader('X-Luczor-Model-Id', $rankedFirst->model_id);
    }

    public function test_invalid_retry_contract_rejects_before_dispatch(): void
    {
        [, $token] = $this->deviceToken();
        $credential = $this->credential('openrouter', 'chat_completions');
        $profile = $this->profile('openrouter', 'test/retry-policy', $credential);
        $this->price($profile);
        $this->useCase('chat', [$profile]);
        $this->policy('proxy.strict', retryStatuses: []);
        $this->expectNoProviderClient();

        $this->proxy($token)
            ->assertStatus(503)
            ->assertJsonPath('code', 'routing_network_policy_retry_statuses_invalid');
        $this->assertSame(0, LlmAttempt::count());
    }

    private function credential(string $provider, ?string $requestFormat): ProviderCredential
    {
        return ProviderCredential::create([
            'provider' => $provider,
            'label' => 'Test '.$provider,
            'api_key' => 'test-secret',
            'request_format' => $requestFormat,
            'active' => true,
        ]);
    }

    /** @param array<int,string> $capabilities */
    private function profile(
        string $provider,
        string $modelId,
        ProviderCredential $credential,
        array $capabilities = ['chat', 'tools', 'vision'],
    ): ModelProfile {
        return ModelProfile::create([
            'name' => $modelId,
            'slug' => str_replace(['/', '.'], '-', $modelId),
            'provider' => $provider,
            'provider_credential_id' => $credential->id,
            'model_id' => $modelId,
            'purpose' => 'chat',
            'temperature' => 0.1,
            'max_tokens' => 100,
            'capabilities' => $capabilities,
            'active' => true,
        ]);
    }

    private function price(ModelProfile $profile, float $input = 1, float $output = 2): void
    {
        ProviderPriceSnapshot::create([
            'provider_id' => $profile->provider,
            'model_id' => $profile->model_id,
            'currency' => 'USD',
            'input_per_million' => $input,
            'output_per_million' => $output,
            'source' => 'test',
            'valid_from' => now()->subMinute(),
        ]);
    }

    /** @param array<int,ModelProfile> $profiles */
    private function useCase(
        string $slug,
        array $profiles,
        ?string $networkPolicyKey = 'proxy.strict',
        int $maxAttempts = 3,
        string $strategy = 'manual',
        int $policyVersion = 1,
    ): ModelUseCase {
        $useCase = ModelUseCase::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'active' => true,
            'policy_version' => $policyVersion,
            'routing_strategy' => $strategy,
            'max_attempts' => $maxAttempts,
            'network_policy_key' => $networkPolicyKey,
        ]);
        foreach ($profiles as $index => $profile) {
            ModelUseCaseEntry::create([
                'model_use_case_id' => $useCase->id,
                'model_profile_id' => $profile->id,
                'sort_order' => $index + 1,
                'active' => true,
            ]);
        }

        return $useCase;
    }

    /** @param array<int,int> $retryStatuses */
    private function policy(
        string $key,
        int $maxAttempts = 3,
        array $retryStatuses = [0, 408, 409, 425, 429, 500, 502, 503, 504, 529],
    ): NetworkPolicy {
        return NetworkPolicy::create([
            'key' => $key,
            'name' => $key,
            'status' => 'active',
            'connect_timeout_ms' => 1000,
            'request_timeout_ms' => 1000,
            'max_attempts' => $maxAttempts,
            'backoff_ms' => 0,
            'max_input_tokens' => 1000,
            'max_output_tokens' => 100,
            'config' => ['retry_statuses' => $retryStatuses],
        ]);
    }

    private function expectNoProviderClient(): void
    {
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldNotReceive('make');
        $this->app->instance(ProviderHttpClientFactory::class, $factory);
    }

    /** @param array<string,mixed> $overrides */
    private function proxy(string $token, array $overrides = [])
    {
        return $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', array_merge([
            'messages' => [['role' => 'user', 'content' => 'Route sicher.']],
            'task_type' => 'chat.general',
        ], $overrides));
    }

    private function successResponse(): Response
    {
        return new Response(200, [], json_encode([
            'id' => 'response-1',
            'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'OK']]],
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{0:User,1:string} */
    private function deviceToken(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        $minted = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Desktop',
            'abilities' => ['proxy.use'],
            'active' => true,
        ]);

        return [$user, $minted['plain']];
    }
}
