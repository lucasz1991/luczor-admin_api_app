<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\LlmRun;
use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\NetworkPolicy;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\ProviderHttpClientFactory;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** P2b — provider wire drivers (Anthropic Messages, OpenAI Responses) + mixed fallback. */
class ProxyProviderDriversTest extends TestCase
{
    use RefreshDatabase;

    public function test_anthropic_profile_speaks_the_messages_wire_and_returns_canonical_json(): void
    {
        [$user, $token] = $this->deviceToken();
        $credential = ProviderCredential::create(['provider' => 'anthropic', 'label' => 'Claude', 'api_key' => 'anthropic-secret', 'active' => true]);
        $this->useCaseWith([$this->profile('anthropic', 'claude-sonnet-5', $credential->id)]);
        $this->policy(['max_attempts' => 1]);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function (string $method, string $url, array $options) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.anthropic.com/v1/messages', $url);
            $this->assertSame('anthropic-secret', $options['headers']['x-api-key']);
            $this->assertSame('2023-06-01', $options['headers']['anthropic-version']);
            $this->assertArrayNotHasKey('Authorization', $options['headers']);
            $this->assertSame('claude-sonnet-5', $options['json']['model']);
            $this->assertSame(50, $options['json']['max_tokens']);
            $this->assertSame([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hallo Claude']]]], $options['json']['messages']);

            return true;
        })->andReturn(new Response(200, [], json_encode([
            'id' => 'msg_1', 'model' => 'claude-sonnet-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Hallo von Claude']],
            'usage' => ['input_tokens' => 11, 'output_tokens' => 5],
        ], JSON_THROW_ON_ERROR)));
        $this->fakeHttp($http);

        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => 'Hallo Claude']],
            'task_type' => 'chat.general',
        ]);

        $response->assertOk()
            ->assertHeader('X-Luczor-Provider', 'anthropic')
            ->assertJsonPath('choices.0.message.content', 'Hallo von Claude')
            ->assertJsonPath('choices.0.finish_reason', 'stop')
            ->assertJsonPath('usage.prompt_tokens', 11);
        $this->assertStringNotContainsString('anthropic-secret', $response->getContent());

        $attempt = LlmRun::query()->where('user_id', $user->id)->firstOrFail()->attempts()->firstOrFail();
        $this->assertSame(11, $attempt->input_tokens);
        $this->assertSame(5, $attempt->output_tokens);
        $this->assertSame('msg_1', $attempt->upstream_generation_id);
    }

    public function test_openai_profile_speaks_the_responses_wire(): void
    {
        [, $token] = $this->deviceToken();
        $credential = ProviderCredential::create(['provider' => 'openai', 'label' => 'OpenAI', 'api_key' => 'openai-secret', 'active' => true]);
        $this->useCaseWith([$this->profile('openai', 'gpt-5', $credential->id)]);
        $this->policy(['max_attempts' => 1]);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function (string $method, string $url, array $options) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.openai.com/v1/responses', $url);
            $this->assertSame('Bearer openai-secret', $options['headers']['Authorization']);
            $this->assertSame(50, $options['json']['max_output_tokens']);
            $this->assertArrayNotHasKey('messages', $options['json']);
            $this->assertSame([['role' => 'user', 'content' => 'Hallo GPT']], $options['json']['input']);

            return true;
        })->andReturn(new Response(200, [], json_encode([
            'id' => 'resp_1', 'model' => 'gpt-5', 'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hallo von GPT']]]],
            'usage' => ['input_tokens' => 7, 'output_tokens' => 2, 'total_tokens' => 9],
        ], JSON_THROW_ON_ERROR)));
        $this->fakeHttp($http);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => 'Hallo GPT']],
            'task_type' => 'chat.general',
        ])->assertOk()
            ->assertHeader('X-Luczor-Provider', 'openai')
            ->assertJsonPath('choices.0.message.content', 'Hallo von GPT')
            ->assertJsonPath('usage.completion_tokens', 2);
    }

    public function test_mixed_provider_chain_falls_back_from_anthropic_to_openrouter(): void
    {
        [$user, $token] = $this->deviceToken();
        $anthropic = ProviderCredential::create(['provider' => 'anthropic', 'label' => 'Claude', 'api_key' => 'anthropic-secret', 'active' => true]);
        $openrouter = ProviderCredential::create(['provider' => 'openrouter', 'label' => 'OR', 'api_key' => 'openrouter-secret', 'active' => true]);
        $this->useCaseWith([
            $this->profile('anthropic', 'claude-sonnet-5', $anthropic->id),
            $this->profile('openrouter', 'meta/llama', $openrouter->id),
        ]);
        $this->policy(['max_attempts' => 2]);

        $urls = [];
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->twice()->andReturnUsing(function (string $method, string $url) use (&$urls) {
            $this->assertSame('POST', $method);
            $urls[] = $url;
            if (count($urls) === 1) {
                return new Response(529, [], 'overloaded');
            }

            return new Response(200, [], json_encode([
                'id' => 'or_1',
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Fallback OK']]],
                'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4],
            ], JSON_THROW_ON_ERROR));
        });
        $this->fakeHttp($http);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => 'Bitte mit Fallback.']],
            'task_type' => 'chat.general',
        ])->assertOk()
            ->assertHeader('X-Luczor-Provider', 'openrouter')
            ->assertJsonPath('choices.0.message.content', 'Fallback OK');

        $this->assertSame('https://api.anthropic.com/v1/messages', $urls[0]);
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $urls[1]);
        $run = LlmRun::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('meta/llama', $run->model_id);
        $this->assertSame(2, $run->attempt_count);
    }

    public function test_anthropic_stream_is_translated_into_canonical_frames(): void
    {
        [$user, $token] = $this->deviceToken();
        $credential = ProviderCredential::create(['provider' => 'anthropic', 'label' => 'Claude', 'api_key' => 'anthropic-secret', 'active' => true]);
        $this->useCaseWith([$this->profile('anthropic', 'claude-sonnet-5', $credential->id)]);
        $this->policy(['max_attempts' => 1]);

        $sse = "event: message_start\n"
            ."data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_s\",\"model\":\"claude-sonnet-5\",\"usage\":{\"input_tokens\":9}}}\n\n"
            ."event: content_block_delta\n"
            ."data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hallo\"}}\n\n"
            ."event: message_delta\n"
            ."data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":3}}\n\n"
            ."event: message_stop\n"
            ."data: {\"type\":\"message_stop\"}\n\n";
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(200, [], $sse));
        $this->fakeHttp($http);

        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'Bitte streame.']],
            'task_type' => 'chat.general',
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $streamed = $response->streamedContent();
        $this->assertStringContainsString('chat.completion.chunk', $streamed);
        $this->assertStringContainsString('"content":"Hallo"', $streamed);
        $this->assertStringContainsString('"finish_reason":"stop"', $streamed);
        $this->assertStringContainsString('data: [DONE]', $streamed);
        // Anthropic's own event dialect must not leak to the client.
        $this->assertStringNotContainsString('content_block_delta', $streamed);

        $attempt = LlmRun::query()->where('user_id', $user->id)->firstOrFail()->attempts()->firstOrFail();
        $this->assertSame(9, $attempt->input_tokens);
        $this->assertSame(3, $attempt->output_tokens);
        $this->assertSame('msg_s', $attempt->upstream_generation_id);
    }

    private function profile(string $provider, string $modelId, int $credentialId): ModelProfile
    {
        return ModelProfile::create([
            'name' => $provider.' '.$modelId, 'slug' => str_replace('/', '-', $provider.'-'.$modelId),
            'provider' => $provider, 'model_id' => $modelId, 'purpose' => 'chat',
            'temperature' => 0.1, 'max_tokens' => 100, 'provider_credential_id' => $credentialId,
        ]);
    }

    /** @param array<int,ModelProfile> $profiles */
    private function useCaseWith(array $profiles): void
    {
        $useCase = ModelUseCase::create(['name' => 'Chat', 'slug' => 'chat', 'active' => true]);
        foreach ($profiles as $index => $profile) {
            ModelUseCaseEntry::create(['model_use_case_id' => $useCase->id, 'model_profile_id' => $profile->id, 'sort_order' => $index + 1, 'active' => true]);
        }
    }

    private function policy(array $overrides = []): NetworkPolicy
    {
        return NetworkPolicy::create(array_merge([
            'key' => 'proxy.openrouter.default', 'name' => 'Default', 'status' => 'active',
            'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000,
            'max_attempts' => 1, 'backoff_ms' => 0, 'max_input_tokens' => 1000, 'max_output_tokens' => 50,
        ], $overrides));
    }

    private function fakeHttp(ClientInterface $http): void
    {
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);
    }

    /** @return array{0: User, 1: string} */
    private function deviceToken(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Desktop', 'abilities' => ['proxy.use'], 'active' => true]);

        return [$user, $minted['plain']];
    }
}
