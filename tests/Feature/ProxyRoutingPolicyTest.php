<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\LlmAttempt;
use App\Models\LlmRun;
use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\NetworkPolicy;
use App\Models\Persona;
use App\Models\ProviderCredential;
use App\Models\Setting;
use App\Models\User;
use App\Services\ProviderHttpClientFactory;
use App\Services\ProviderPolicyService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

class ProxyRoutingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_model_and_policy_values_cannot_bypass_the_server_input_budget(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        $this->chatProfiles();
        NetworkPolicy::create([
            'key' => 'proxy.openrouter.default',
            'name' => 'Strict default',
            'status' => 'active',
            'connect_timeout_ms' => 1000,
            'request_timeout_ms' => 1000,
            'max_attempts' => 1,
            'backoff_ms' => 0,
            'max_input_tokens' => 1,
            'max_output_tokens' => 20,
        ]);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'model' => 'attacker/free-model',
            'temperature' => 2,
            'max_tokens' => 200000,
            'network_policy_id' => 'client-bypass-policy',
            'messages' => [['role' => 'user', 'content' => str_repeat('budgetiert ', 20)]],
            'task_type' => 'chat.general',
        ])->assertStatus(422)->assertJsonStructure(['message', 'request_id']);

        $this->assertDatabaseHas('llm_runs', [
            'user_id' => $user->id,
            'task_type' => 'chat.general',
            'model_id' => 'pending',
            'status' => 'budget_rejected',
            'network_policy_id' => 'proxy.openrouter.default',
        ]);
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_proxy_uses_the_admin_profile_and_records_provider_usage_without_exposing_the_provider_key(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        [$primary] = $this->chatProfiles();
        NetworkPolicy::create([
            'key' => 'proxy.openrouter.default', 'name' => 'Default', 'status' => 'active',
            'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000,
            'max_attempts' => 1, 'backoff_ms' => 0, 'max_input_tokens' => 1000, 'max_output_tokens' => 50,
        ]);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function (string $method, string $url, array $options) use ($primary) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $url);
            $this->assertSame($primary->model_id, $options['json']['model']);
            $this->assertSame($primary->temperature, $options['json']['temperature']);
            $this->assertSame(50, $options['json']['max_tokens']);
            $this->assertSame(['exclude' => true], $options['json']['reasoning']);
            $this->assertArrayNotHasKey('network_policy_id', $options['json']);
            $this->assertSame('Bearer test-secret', $options['headers']['Authorization']);

            return true;
        })->andReturn(new Response(200, ['X-Generation-Id' => 'generation-1'], json_encode([
            'id' => 'response-1',
            'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'OK']]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 7, 'cost' => 0.000321],
        ], JSON_THROW_ON_ERROR)));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'model' => 'attacker/free-model', 'temperature' => 2, 'max_tokens' => 200000,
            'network_policy_id' => 'client-bypass-policy',
            'messages' => [['role' => 'user', 'content' => 'Bitte prüfe die Server-Regeln.']],
            'task_type' => 'chat.general',
        ]);

        $response->assertOk()->assertHeader('X-Luczor-Request-Id')->assertJsonPath('choices.0.message.content', 'OK');
        $this->assertStringNotContainsString('test-secret', $response->getContent());
        $run = LlmRun::query()->where('user_id', $user->id)->firstOrFail();
        $attempt = $run->attempts()->firstOrFail();
        $this->assertSame($primary->model_id, $run->model_id);
        $this->assertSame('proxy.openrouter.default', $run->network_policy_id);
        $this->assertSame(12, $attempt->input_tokens);
        $this->assertSame(7, $attempt->output_tokens);
        $this->assertSame(0.000321, $attempt->provider_cost);
    }

    public function test_admin_fallback_chain_is_used_even_when_a_client_requests_a_different_model(): void
    {
        [$primary, $fallback] = $this->chatProfiles();

        $candidates = app(ProviderPolicyService::class)->candidates('attacker/free-model', 'chat.general');

        $this->assertSame([$primary->id, $fallback->id], array_map(fn (ModelProfile $profile) => $profile->id, $candidates));
    }

    public function test_retriable_provider_failure_uses_the_next_admin_fallback_and_records_both_attempts(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        [, $fallback] = $this->chatProfiles();
        NetworkPolicy::create([
            'key' => 'proxy.openrouter.default', 'name' => 'Fallback', 'status' => 'active',
            'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000,
            'max_attempts' => 2, 'backoff_ms' => 0, 'max_input_tokens' => 1000, 'max_output_tokens' => 100,
        ]);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->twice()->andReturn(
            new Response(503, [], 'temporarily unavailable'),
            new Response(200, [], json_encode([
                'id' => 'fallback-response',
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Fallback OK']]],
                'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4, 'cost' => 0.0002],
            ], JSON_THROW_ON_ERROR)),
        );
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => 'Bitte mit Fallback antworten.']], 'task_type' => 'chat.general',
        ])->assertOk()->assertJsonPath('choices.0.message.content', 'Fallback OK');

        $run = LlmRun::query()->where('user_id', $user->id)->firstOrFail();
        $attempts = $run->attempts()->orderBy('attempt_no')->get();
        $this->assertSame($fallback->model_id, $run->model_id);
        $this->assertSame(2, $run->attempt_count);
        $this->assertSame(1, $run->retry_count);
        $this->assertSame('failed', $attempts[0]->status);
        $this->assertSame('completed', $attempts[1]->status);
    }

    public function test_provider_auth_failure_is_returned_as_actionable_server_error_without_upstream_body(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        $this->chatProfiles();
        NetworkPolicy::create([
            'key' => 'proxy.openrouter.default', 'name' => 'Auth failure', 'status' => 'active',
            'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000,
            'max_attempts' => 1, 'backoff_ms' => 0, 'max_input_tokens' => 1000, 'max_output_tokens' => 100,
        ]);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(401, [], 'User not found: upstream-secret-detail'));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => 'Bitte teste den Provider-Key.']], 'task_type' => 'chat.general',
        ]);

        $response->assertStatus(502)
            ->assertJsonPath('code', 'provider_auth_failed')
            ->assertJsonPath('provider_status', 401)
            ->assertHeader('X-Luczor-Request-Id');
        $this->assertStringNotContainsString('upstream-secret-detail', $response->getContent());
        $this->assertDatabaseHas('llm_runs', ['user_id' => $user->id, 'status' => 'error']);
        $this->assertDatabaseHas('llm_attempts', ['error_type' => 'provider_auth_failed', 'http_status' => 401]);
    }

    public function test_streaming_response_persists_final_usage_after_the_stream_finishes(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        $this->chatProfiles();
        NetworkPolicy::create([
            'key' => 'proxy.openrouter.default', 'name' => 'Stream', 'status' => 'active',
            'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000,
            'max_attempts' => 1, 'backoff_ms' => 0, 'max_input_tokens' => 1000, 'max_output_tokens' => 100,
        ]);
        $sse = "data: {\"id\":\"stream-1\",\"choices\":[{\"delta\":{\"content\":\"Hallo\"},\"finish_reason\":null}]}\n\n"
            ."data: {\"id\":\"stream-1\",\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":9,\"completion_tokens\":3,\"cost\":0.000123}}\n\n"
            ."data: [DONE]\n\n";
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(200, ['X-Generation-Id' => 'stream-1'], $sse));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'stream' => true, 'messages' => [['role' => 'user', 'content' => 'Bitte streame.']], 'task_type' => 'chat.general',
        ]);
        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Hallo', $response->streamedContent());

        $attempt = LlmAttempt::query()->whereHas('run', fn ($query) => $query->where('user_id', $user->id))->firstOrFail();
        $this->assertSame(9, $attempt->input_tokens);
        $this->assertSame(3, $attempt->output_tokens);
        $this->assertSame(0.000123, $attempt->provider_cost);
        $this->assertSame('stream-1', $attempt->upstream_generation_id);
    }

    public function test_proxy_rejects_an_oversized_request_before_provider_dispatch(): void
    {
        [, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        config()->set('luczor.proxy.max_request_bytes', 128);

        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldNotReceive('make');
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => str_repeat('x', 256)]],
        ])->assertStatus(413)
            ->assertJsonPath('code', 'proxy_request_too_large')
            ->assertJsonPath('limit_bytes', 128);

        $this->assertSame(0, LlmRun::count());
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_provider_admission_still_precedes_payload_validation(): void
    {
        [, $token] = $this->deviceToken(['proxy.use']);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [],
        ])->assertStatus(503)
            ->assertJsonPath('message', 'Kein aktiver Provider im Server konfiguriert.');

        $this->assertSame(0, LlmRun::count());
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_proxy_rate_limit_still_precedes_payload_validation(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $apiKey = ApiKey::query()->where('user_id', $user->id)->firstOrFail();
        $rateKey = 'proxy:'.$user->id.':'.$apiKey->id;
        config()->set('luczor.proxy.requests_per_minute', 1);
        RateLimiter::clear($rateKey);
        RateLimiter::hit($rateKey, 60);

        try {
            $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
                'messages' => [],
            ])->assertStatus(429)
                ->assertJsonPath('message', 'Provider rate limit exceeded.')
                ->assertHeader('Retry-After');
        } finally {
            RateLimiter::clear($rateKey);
        }

        $this->assertSame(0, LlmRun::count());
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_non_streaming_response_is_rejected_while_reading_at_limit_plus_one_byte(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        $this->chatProfiles();
        $this->defaultNetworkPolicy();
        config()->set('luczor.proxy.max_response_bytes', 256);

        $readBytes = 0;
        $body = $this->trackedUnknownSizeStream(str_repeat('x', 4096), $readBytes);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function (string $method, string $url, array $options): bool {
            $this->assertSame('POST', $method);
            $this->assertTrue($options['stream']);

            return true;
        })->andReturn(new Response(200, [], $body));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => 'Antworte umfangreich.']],
            'task_type' => 'chat.general',
        ])->assertStatus(502)
            ->assertJsonPath('code', 'upstream_response_too_large')
            ->assertJsonPath('provider_status', 200)
            ->assertJsonPath('limit_bytes', 256)
            ->assertHeader('X-Luczor-Request-Id');

        $this->assertSame(257, $readBytes);
        $this->assertDatabaseHas('llm_runs', ['user_id' => $user->id, 'status' => 'error']);
        $this->assertDatabaseHas('llm_attempts', [
            'http_status' => 200,
            'status' => 'failed',
            'error_type' => 'upstream_response_too_large',
        ]);
    }

    public function test_oversized_retry_body_keeps_the_admin_fallback_available(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        [, $fallback] = $this->chatProfiles();
        $this->defaultNetworkPolicy(2);
        config()->set('luczor.proxy.max_response_bytes', 512);

        $readBytes = 0;
        $oversizedBody = $this->trackedUnknownSizeStream(str_repeat('x', 4096), $readBytes);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->twice()->withArgs(function (string $method, string $url, array $options): bool {
            $this->assertSame('POST', $method);
            $this->assertTrue($options['stream']);

            return true;
        })->andReturn(
            new Response(503, [], $oversizedBody),
            new Response(200, [], json_encode([
                'id' => 'bounded-fallback',
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Fallback OK']]],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
            ], JSON_THROW_ON_ERROR)),
        );
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => 'Nutze den sicheren Fallback.']],
            'task_type' => 'chat.general',
        ])->assertOk()->assertJsonPath('choices.0.message.content', 'Fallback OK');

        $attempts = LlmAttempt::query()
            ->whereHas('run', fn ($query) => $query->where('user_id', $user->id))
            ->orderBy('attempt_no')
            ->get();
        $this->assertSame(513, $readBytes);
        $this->assertSame('upstream_response_too_large', $attempts[0]->error_type);
        $this->assertSame(503, $attempts[0]->http_status);
        $this->assertSame('failed', $attempts[0]->status);
        $this->assertSame($fallback->model_id, $attempts[1]->model_id);
        $this->assertSame('completed', $attempts[1]->status);
    }

    public function test_provider_auth_failure_takes_precedence_without_reading_the_error_body(): void
    {
        [, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        $this->chatProfiles();
        $this->defaultNetworkPolicy();
        config()->set('luczor.proxy.max_response_bytes', 1);

        $readBytes = 0;
        $body = $this->trackedUnknownSizeStream('upstream-secret-detail', $readBytes);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(401, [], $body));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => 'Prüfe den Provider-Key.']],
            'task_type' => 'chat.general',
        ]);

        $response->assertStatus(502)
            ->assertJsonPath('code', 'provider_auth_failed')
            ->assertJsonPath('provider_status', 401);
        $this->assertSame(0, $readBytes);
        $this->assertStringNotContainsString('upstream-secret-detail', $response->getContent());
    }

    public function test_stream_total_limit_emits_a_canonical_error_frame_and_fails_telemetry(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        $this->chatProfiles();
        $this->defaultNetworkPolicy();
        config()->set('luczor.proxy.max_stream_bytes', 180);
        config()->set('luczor.proxy.max_stream_frame_bytes', 1024);

        $firstFrame = "data: {\"id\":\"bounded-stream\",\"choices\":[{\"delta\":{\"content\":\"Hallo\"},\"finish_reason\":null}]}\n\n";
        $bodyContents = $firstFrame."data: [DONE]\n\n".'data: '.str_repeat('x', 4096)."\n\n";
        $readBytes = 0;
        $body = $this->trackedUnknownSizeStream($bodyContents, $readBytes);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(200, [], $body));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'Streame begrenzt.']],
            'task_type' => 'chat.general',
        ]);
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertSame(181, $readBytes);
        $this->assertStringContainsString('Hallo', $content);
        $this->assertStringContainsString('"code":"upstream_stream_too_large"', $content);
        $this->assertSame(1, substr_count($content, 'data: [DONE]'));
        $this->assertLessThan(
            strpos($content, 'data: [DONE]'),
            strpos($content, '"code":"upstream_stream_too_large"'),
        );
        $this->assertDatabaseHas('llm_runs', ['user_id' => $user->id, 'status' => 'error']);
        $this->assertDatabaseHas('llm_attempts', [
            'http_status' => 200,
            'status' => 'failed',
            'error_type' => 'upstream_stream_too_large',
        ]);
    }

    public function test_declared_oversized_stream_is_rejected_with_http_502_before_body_reading(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        $this->chatProfiles();
        $this->defaultNetworkPolicy();
        config()->set('luczor.proxy.max_stream_bytes', 256);

        $readBytes = 0;
        $body = $this->trackedUnknownSizeStream(str_repeat('x', 4096), $readBytes);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(200, ['Content-Length' => '4096'], $body));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'Streame mit bekannter Länge.']],
            'task_type' => 'chat.general',
        ])->assertStatus(502)
            ->assertJsonPath('code', 'upstream_stream_too_large')
            ->assertJsonPath('provider_status', 200)
            ->assertJsonPath('limit_bytes', 256);

        $this->assertSame(0, $readBytes);
        $this->assertDatabaseHas('llm_runs', ['user_id' => $user->id, 'status' => 'error']);
        $this->assertDatabaseHas('llm_attempts', [
            'http_status' => 200,
            'status' => 'failed',
            'error_type' => 'upstream_stream_too_large',
        ]);
    }

    public function test_stream_frame_limit_never_emits_the_oversized_frame(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        $this->chatProfiles();
        $this->defaultNetworkPolicy();
        config()->set('luczor.proxy.max_stream_bytes', 4096);
        config()->set('luczor.proxy.max_stream_frame_bytes', 128);

        $oversizedContent = str_repeat('frame-secret-', 32);
        $body = 'data: '.json_encode([
            'id' => 'oversized-frame',
            'choices' => [['delta' => ['content' => $oversizedContent], 'finish_reason' => null]],
        ], JSON_THROW_ON_ERROR)."\n\n";
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(200, [], $body));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'Streame mit Frame-Grenze.']],
            'task_type' => 'chat.general',
        ]);
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('"code":"upstream_stream_frame_too_large"', $content);
        $this->assertStringNotContainsString($oversizedContent, $content);
        $this->assertDatabaseHas('llm_runs', ['user_id' => $user->id, 'status' => 'error']);
        $this->assertDatabaseHas('llm_attempts', [
            'http_status' => 200,
            'status' => 'failed',
            'error_type' => 'upstream_stream_frame_too_large',
        ]);
    }

    public function test_customer_cannot_read_model_catalog_or_llm_routing(): void
    {
        [, $token] = $this->deviceToken(['settings.read', 'brain.read']);

        $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/model-profiles')->assertForbidden();
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/llm/route', ['task_type' => 'chat.general'])->assertForbidden();
    }

    public function test_admin_can_export_attempt_telemetry_as_jsonl(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $run = LlmRun::create([
            'request_id' => 'telemetry-export-1',
            'user_id' => $admin->id,
            'task_type' => 'chat.general',
            'model_id' => 'admin/model',
            'provider_id' => 'openrouter',
            'status' => 'ok',
            'success' => true,
        ]);
        LlmAttempt::create([
            'llm_run_id' => $run->id,
            'attempt_no' => 1,
            'provider_id' => 'openrouter',
            'model_id' => 'admin/model',
            'status' => 'completed',
            'http_status' => 200,
            'total_ms' => 120,
            'effective_cost' => 0.0001,
        ]);

        $response = $this->actingAs($admin)->get('/dashboard/telemetry/export?format=jsonl&days=30');

        $response->assertOk();
        $this->assertStringContainsString('telemetry-export-1', $response->streamedContent());
    }

    /** @return array{0: ModelProfile, 1: ModelProfile} */
    public function test_active_persona_is_injected_into_the_prompt(): void
    {
        [, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        $this->chatProfiles();
        NetworkPolicy::create([
            'key' => 'proxy.openrouter.default', 'name' => 'Default', 'status' => 'active',
            'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000,
            'max_attempts' => 1, 'backoff_ms' => 0, 'max_input_tokens' => 1000, 'max_output_tokens' => 50,
        ]);
        Persona::create(['slug' => 'knapp', 'name' => 'Knapp', 'prompt' => 'PERSONA: antworte knapp.', 'active' => true]);
        Setting::putValue('active_persona', 'knapp', ['group' => 'client', 'type' => 'string']);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function (string $method, string $url, array $options) {
            $this->assertSame('POST', $method);
            $systemContents = array_map(
                fn ($m) => $m['content'] ?? '',
                array_filter($options['json']['messages'], fn ($m) => ($m['role'] ?? '') === 'system')
            );
            $this->assertContains('PERSONA: antworte knapp.', $systemContents);

            return true;
        })->andReturn(new Response(200, [], json_encode([
            'id' => 'r1', 'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'OK']]],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => 'Hallo']],
            'task_type' => 'chat.general',
        ])->assertOk();
    }

    public function test_a_use_case_budget_tightens_below_the_network_policy(): void
    {
        [$user, $token] = $this->deviceToken(['proxy.use']);
        $this->openRouterCredential();
        $this->chatProfiles(); // creates the 'chat' use case
        ModelUseCase::where('slug', 'chat')->update(['max_input_tokens' => 1]);
        NetworkPolicy::create([
            'key' => 'proxy.openrouter.default', 'name' => 'Lenient', 'status' => 'active',
            'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000,
            'max_attempts' => 1, 'backoff_ms' => 0, 'max_input_tokens' => 1000000, 'max_output_tokens' => 50,
        ]);

        // The global policy would allow this, but the use-case budget rejects it.
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => str_repeat('lang ', 40)]],
            'task_type' => 'chat.general',
        ])->assertStatus(422)->assertJsonStructure(['message', 'request_id']);

        $this->assertDatabaseHas('llm_runs', ['user_id' => $user->id, 'status' => 'budget_rejected']);
        $this->assertSame(0, LlmAttempt::count());
    }

    private function chatProfiles(): array
    {
        $primary = ModelProfile::create(['name' => 'Admin Primary', 'slug' => 'admin-primary', 'provider' => 'openrouter', 'model_id' => 'admin/primary', 'purpose' => 'chat', 'temperature' => 0.1, 'max_tokens' => 100]);
        $fallback = ModelProfile::create(['name' => 'Admin Fallback', 'slug' => 'admin-fallback', 'provider' => 'openrouter', 'model_id' => 'admin/fallback', 'purpose' => 'chat', 'temperature' => 0.1, 'max_tokens' => 100]);
        $useCase = ModelUseCase::create(['name' => 'Chat', 'slug' => 'chat', 'active' => true]);
        ModelUseCaseEntry::create(['model_use_case_id' => $useCase->id, 'model_profile_id' => $primary->id, 'sort_order' => 1, 'active' => true]);
        ModelUseCaseEntry::create(['model_use_case_id' => $useCase->id, 'model_profile_id' => $fallback->id, 'sort_order' => 2, 'active' => true]);

        return [$primary, $fallback];
    }

    private function openRouterCredential(): void
    {
        ProviderCredential::create(['provider' => 'openrouter', 'label' => 'Test', 'api_key' => 'test-secret', 'active' => true]);
    }

    private function defaultNetworkPolicy(int $maxAttempts = 1): NetworkPolicy
    {
        return NetworkPolicy::create([
            'key' => 'proxy.openrouter.default',
            'name' => 'Bounded proxy',
            'status' => 'active',
            'connect_timeout_ms' => 1000,
            'request_timeout_ms' => 1000,
            'max_attempts' => $maxAttempts,
            'backoff_ms' => 0,
            'max_input_tokens' => 1000,
            'max_output_tokens' => 100,
        ]);
    }

    private function trackedUnknownSizeStream(string $contents, int &$readBytes): StreamInterface
    {
        $inner = Utils::streamFor($contents);

        return FnStream::decorate($inner, [
            'getSize' => fn (): null => null,
            'read' => function (int $length) use ($inner, &$readBytes): string {
                $chunk = $inner->read($length);
                $readBytes += strlen($chunk);

                return $chunk;
            },
            'getContents' => function () use ($inner, &$readBytes): string {
                $chunk = $inner->getContents();
                $readBytes += strlen($chunk);

                return $chunk;
            },
            '__toString' => function () use ($inner, &$readBytes): string {
                $chunk = (string) $inner;
                $readBytes += strlen($chunk);

                return $chunk;
            },
        ]);
    }

    /** @return array{0: User, 1: string} */
    private function deviceToken(array $abilities): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Desktop', 'abilities' => $abilities, 'active' => true]);

        return [$user, $minted['plain']];
    }
}
