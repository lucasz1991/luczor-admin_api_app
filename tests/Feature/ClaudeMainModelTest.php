<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\LlmRun;
use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\NetworkPolicy;
use App\Models\ProviderCredential;
use App\Models\ProviderPriceSnapshot;
use App\Models\User;
use App\Services\ClaudeAccessService;
use App\Services\ClaudeProfileInstaller;
use App\Services\ProviderHttpClientFactory;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** Claude as user-selected main model: chat.main routing, key resolution, composer list. */
class ClaudeMainModelTest extends TestCase
{
    use RefreshDatabase;

    private const PERSONAL_KEY = 'fake-personal-claude-key-1111';

    private const ORG_KEY = 'fake-organization-claude-key-2222';

    protected function setUp(): void
    {
        parent::setUp();
        app(ClaudeProfileInstaller::class)->install();
    }

    public function test_installer_is_idempotent_and_preserves_admin_changes(): void
    {
        $this->assertSame(
            ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-2'],
            ModelUseCase::query()->where('slug', 'main-claude')->sole()->entries->map(fn ($entry) => $entry->modelProfile->slug)->all(),
        );
        $opus = ModelProfile::query()->where('slug', 'claude-opus-5-5')->sole();
        $this->assertSame('anthropic', $opus->provider);
        $this->assertSame('claude-opus-5-5', $opus->model_id);
        $this->assertNull($opus->provider_credential_id);
        $this->assertSame(1000000, $opus->context_window);
        $this->assertSame(64000, $opus->max_tokens);
        $this->assertContains('tools', $opus->capabilities);
        $this->assertSame('Claude Opus 5.5', $opus->meta['composer_label']);
        $this->assertSame('Leistungsstark für anspruchsvolle Aufgaben', $opus->meta['composer_description']);
        $this->assertTrue($opus->meta['composer_visible']);
        $this->assertSame(['fast', 'balanced', 'thorough', 'max', 'ultra'], $opus->meta['efforts']);

        $fable = ModelProfile::query()->where('slug', 'claude-fable-5-2')->sole();
        $this->assertFalse($fable->meta['composer_visible']);
        $this->assertFalse(ModelUseCaseEntry::query()->where('model_profile_id', $fable->id)->sole()->active);

        foreach (['claude-opus-5-5' => [4.0, 20.0], 'claude-sonnet-5-5' => [2.0, 10.0], 'claude-haiku-5-5' => [0.10, 0.50], 'claude-fable-5-2' => [8.0, 40.0]] as $model => [$input, $output]) {
            $price = ProviderPriceSnapshot::current('anthropic', $model);
            $this->assertSame('USD', $price->currency);
            $this->assertSame($input, $price->input_per_million);
            $this->assertSame($output, $price->output_per_million);
        }

        // Re-running (directly and through artisan) creates nothing and keeps edits.
        $opus->update(['meta' => ['composer_visible' => false] + $opus->meta]);
        $this->assertSame(['profiles' => 0, 'entries' => 0, 'prices' => 0], app(ClaudeProfileInstaller::class)->install());
        $this->artisan('luczor:claude:install-profiles')->assertSuccessful();
        $this->assertSame(4, ModelProfile::query()->where('provider', 'anthropic')->count());
        $this->assertSame(4, ProviderPriceSnapshot::query()->where('provider_id', 'anthropic')->count());
        $this->assertFalse($opus->fresh()->meta['composer_visible']);
    }

    public function test_bootstrap_lists_exactly_the_visible_active_models_in_admin_order(): void
    {
        [, $token] = $this->token(['settings.read']);

        $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/runtime-settings')->assertOk()
            ->assertJsonPath('routing.client_model_selection', false)
            ->assertJsonPath('routing.main_model', [
                'claude_modes_allowed' => false,
                'key_sources' => ['user'],
                'default_model' => 'claude-opus-5-5',
                'models' => [
                    ['id' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5', 'description' => 'Leistungsstark für anspruchsvolle Aufgaben', 'efforts' => ['fast', 'balanced', 'thorough', 'max', 'ultra']],
                    ['id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5', 'description' => 'Schnell und vielseitig für den Alltag', 'efforts' => ['fast', 'balanced', 'thorough', 'max', 'ultra']],
                    ['id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5', 'description' => 'Am schnellsten, für einfache Aufgaben', 'efforts' => ['fast', 'balanced', 'thorough', 'max', 'ultra']],
                ],
                // Per-account setup; this account has configured nothing yet.
                'access' => ['connected' => false, 'source' => null, 'provider_fallback' => false, 'preferred_model' => null],
            ]);

        // Admin: release the modes + organization access, hide Opus, reorder Haiku first.
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $this->organizationCredential();
        $this->actingAs($admin)->post(route('dashboard.claude-settings.update'), ['settings' => [
            'claude_modes_allowed' => '1', 'claude_org_key_for_users' => '1',
        ]])->assertRedirect(route('admin.page', 'providers'));
        $opus = ModelProfile::query()->where('slug', 'claude-opus-5-5')->sole();
        $this->actingAs($admin)->post(route('dashboard.model-profiles.composer-visibility', $opus))->assertRedirect();
        $haiku = ModelProfile::query()->where('slug', 'claude-haiku-5-5')->sole();
        ModelUseCaseEntry::query()->where('model_profile_id', $haiku->id)->update(['sort_order' => 0]);

        $main = $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/runtime-settings')->assertOk()->json('routing.main_model');
        $this->assertTrue($main['claude_modes_allowed']);
        // Unchecked checkbox: personal keys were switched off by the same form post.
        $this->assertSame(['organization'], $main['key_sources']);
        $this->assertSame('claude-haiku-5-5', $main['default_model']);
        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], array_column($main['models'], 'id'));

        // Profiles outside main-claude have no composer switch; non-admins have no access.
        $foreign = ModelProfile::create(['name' => 'Other', 'slug' => 'other', 'provider' => 'openrouter', 'model_id' => 'x/y', 'active' => true]);
        $this->actingAs($admin)->post(route('dashboard.model-profiles.composer-visibility', $foreign))->assertNotFound();
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        $this->actingAs($user)->post(route('dashboard.claude-settings.update'), [])->assertRedirect();
        $this->assertTrue(app(ClaudeAccessService::class)->modesAllowed());
    }

    public function test_admin_providers_page_shows_claude_controls_and_personal_key_owner(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $owner = User::factory()->create(['email_verified_at' => now(), 'role' => 'user', 'email' => 'owner@example.test']);
        $this->personalCredential($owner);

        $this->actingAs($admin)->get(route('admin.page', 'providers'))->assertOk()
            ->assertSee('Persönlicher Zugang von owner@example.test')
            ->assertSee('name="settings[claude_modes_allowed]"', false)
            ->assertSee('name="settings[claude_user_keys_allowed]"', false)
            ->assertSee('name="settings[claude_org_key_for_users]"', false)
            ->assertSee('<option value="anthropic"', false)
            ->assertSee('Claude Sonnet 5.5')
            ->assertDontSee(self::PERSONAL_KEY);

        // Personal keys can never back a shared model profile.
        $this->actingAs($admin)->get(route('admin.page', 'models'))->assertOk()->assertDontSee('Persönlicher Claude-Zugang');
    }

    public function test_chat_main_is_rejected_while_the_modes_are_not_released(): void
    {
        [$user, $token] = $this->token();
        $this->personalCredential($user);
        $this->forbidProviderCalls();

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $this->mainRequest())
            ->assertStatus(403)->assertJsonPath('code', 'claude_modes_disabled')->assertJsonPath('error_code', 'claude_modes_disabled');
        $this->assertSame(0, LlmRun::query()->count());
    }

    public function test_chat_main_without_any_usable_key_reports_missing_access(): void
    {
        [$user, $token] = $this->token();
        $this->releaseModes();
        // A global key exists but is not released to users; another user's key is irrelevant.
        $this->organizationCredential();
        $this->personalCredential(User::factory()->create());
        $this->forbidProviderCalls();

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $this->mainRequest())
            ->assertStatus(403)->assertJsonPath('code', 'claude_access_missing');

        // An admin-deactivated personal key does not count either.
        $this->personalCredential($user)->update(['active' => false]);
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $this->mainRequest())
            ->assertStatus(403)->assertJsonPath('code', 'claude_access_missing');
    }

    public function test_chat_main_rejects_models_that_are_not_released(): void
    {
        [$user, $token] = $this->token();
        $this->releaseModes();
        $this->personalCredential($user);
        ModelProfile::create(['name' => 'Chat only', 'slug' => 'chat-only', 'provider' => 'openrouter', 'model_id' => 'x/y', 'active' => true]);
        $this->forbidProviderCalls();

        // Inactive entry (Fable), profile outside main-claude, unknown slug.
        foreach (['claude-fable-5-2', 'chat-only', 'claude-unknown'] as $slug) {
            $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $this->mainRequest(['main_model_profile' => $slug]))
                ->assertStatus(422)->assertJsonPath('code', 'claude_model_not_allowed');
        }
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $this->mainRequest(['effort' => 'ultra']))
            ->assertStatus(422)->assertJsonValidationErrors('effort');
    }

    public function test_chat_main_runs_the_selected_model_on_the_personal_key_with_effort_tools_and_fallback(): void
    {
        [$user, $token] = $this->token();
        $this->releaseModes();
        $personal = $this->personalCredential($user);
        $this->organizationCredential();
        app(ClaudeAccessService::class)->putSetting(ClaudeAccessService::SETTING_ORG_KEY_FOR_USERS, true);
        app(ClaudeAccessService::class)->setProviderFallback($user->id, true);
        $tools = [['type' => 'function', 'function' => ['name' => 'file_read', 'description' => 'Liest eine Datei', 'parameters' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]]]];

        $this->fakeProvider(function (string $method, string $url, array $options): Response {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.anthropic.com/v1/messages', $url);
            $this->assertSame(self::PERSONAL_KEY, $options['headers']['x-api-key']);
            $this->assertSame('2026-10-21', $options['headers']['anthropic-version']);
            $this->assertSame('server-side-fallback-2026-07-01', $options['headers']['anthropic-beta']);
            $json = $options['json'];
            $this->assertSame('claude-sonnet-5-5', $json['model']);
            $this->assertSame(['effort' => 'xhigh'], $json['output_config']);
            $this->assertSame(['type' => 'adaptive'], $json['thinking']);
            $this->assertSame('default', $json['fallbacks']);
            $this->assertSame(64000, $json['max_tokens']);
            $this->assertSame('file_read', $json['tools'][0]['name']);
            $this->assertArrayNotHasKey('tool_choice', $json);
            $this->assertArrayNotHasKey('temperature', $json);
            $this->assertArrayNotHasKey('provider_fallback', $json);
            $this->assertArrayNotHasKey('effort', $json);

            return new Response(200, [], json_encode([
                'id' => 'msg_main', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'tool_use',
                'content' => [
                    ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'file_read', 'input' => ['path' => 'a.txt']],
                ],
                'usage' => ['input_tokens' => 20, 'output_tokens' => 9, 'cache_read_input_tokens' => 500],
            ], JSON_THROW_ON_ERROR));
        });

        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $this->mainRequest([
            'main_model_profile' => 'claude-sonnet-5-5', 'effort' => 'xhigh', 'tools' => $tools, 'tool_choice' => 'required',
        ]));

        $response->assertOk()
            ->assertHeader('X-Luczor-Provider', 'anthropic')
            ->assertHeader('X-Luczor-Use-Case', 'main-claude')
            ->assertHeader('X-Luczor-Model-Profile', 'claude-sonnet-5-5')
            ->assertHeader('X-Luczor-Model-Id', 'claude-sonnet-5-5')
            ->assertJsonPath('choices.0.finish_reason', 'tool_calls')
            ->assertJsonPath('choices.0.message.tool_calls.0.function.name', 'file_read')
            ->assertJsonPath('usage.prompt_tokens_details.cached_tokens', 500);
        $this->assertStringNotContainsString(self::PERSONAL_KEY, $response->getContent());

        $attempt = LlmRun::query()->where('user_id', $user->id)->sole()->attempts()->sole();
        $this->assertSame($personal->id, $attempt->provider_credential_id);
        $this->assertSame('claude-sonnet-5-5', $attempt->model_id);
        $this->assertSame(500, $attempt->cache_read_tokens);
    }

    public function test_chat_main_defaults_to_the_use_case_order_and_the_released_organization_key(): void
    {
        [, $token] = $this->token();
        $this->releaseModes();
        $this->organizationCredential();
        app(ClaudeAccessService::class)->putSetting(ClaudeAccessService::SETTING_ORG_KEY_FOR_USERS, true);

        $this->fakeProvider(function (string $method, string $url, array $options): Response {
            $this->assertSame(self::ORG_KEY, $options['headers']['x-api-key']);
            $this->assertArrayNotHasKey('anthropic-beta', $options['headers']);
            $this->assertSame('claude-opus-5-5', $options['json']['model']);
            $this->assertArrayNotHasKey('output_config', $options['json']);
            $this->assertArrayNotHasKey('fallbacks', $options['json']);

            return new Response(200, [], json_encode([
                'id' => 'msg_org', 'model' => 'claude-opus-5-5', 'stop_reason' => 'refusal',
                'stop_details' => ['type' => 'refusal', 'category' => 'cyber'], 'content' => [],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 0],
            ], JSON_THROW_ON_ERROR));
        });

        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $this->mainRequest());

        $response->assertOk()
            ->assertHeader('X-Luczor-Model-Profile', 'claude-opus-5-5')
            ->assertJsonPath('choices.0.finish_reason', 'content_filter')
            ->assertJsonPath('luczor_refusal', ['category' => 'cyber', 'phase' => 'before_output']);
        $this->assertStringNotContainsString(self::ORG_KEY, $response->getContent());
    }

    public function test_chat_main_stream_carries_a_mid_stream_refusal_in_the_final_frame(): void
    {
        [$user, $token] = $this->token();
        $this->releaseModes();
        $this->personalCredential($user);

        $sse = 'data: '.json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_s', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 9]]])."\n\n"
            .'data: '.json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']])."\n\n"
            .'data: '.json_encode(['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Teil']])."\n\n"
            .'data: '.json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'bio']], 'usage' => ['output_tokens' => 2]])."\n\n"
            .'data: '.json_encode(['type' => 'message_stop'])."\n\n";
        $this->fakeProvider(function (string $method, string $url, array $options) use ($sse): Response {
            $this->assertTrue($options['json']['stream']);
            $this->assertSame('claude-haiku-5-5', $options['json']['model']);
            $this->assertSame(64000, $options['json']['max_tokens']);
            $this->assertSame('text/event-stream', $options['headers']['Accept']);

            return new Response(200, [], $sse);
        });
        // Haiku has no server-side fallback even when the user opted in.
        app(ClaudeAccessService::class)->setProviderFallback($user->id, true);

        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $this->mainRequest([
            'main_model_profile' => 'claude-haiku-5-5', 'stream' => true,
        ]));

        $response->assertOk();
        $streamed = $response->streamedContent();
        $this->assertStringContainsString('"content":"Teil"', $streamed);
        $this->assertStringContainsString('"finish_reason":"content_filter"', $streamed);
        $this->assertStringContainsString('"luczor_refusal":{"category":"bio","phase":"mid_stream"}', $streamed);
        $this->assertStringContainsString('data: [DONE]', $streamed);
        $this->assertSame('content_filter', LlmRun::query()->where('user_id', $user->id)->sole()->finish_reason);
    }

    public function test_deleting_the_personal_key_revokes_chat_main_immediately(): void
    {
        [$user, $token] = $this->token(['proxy.use', 'provider.keys']);
        $this->releaseModes();
        $this->personalCredential($user);

        $this->withHeader('X-Api-Key', $token)->deleteJson('/api/v1/provider-keys/anthropic')->assertOk()->assertJsonPath('connected', false);
        $this->forbidProviderCalls();
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $this->mainRequest(['main_model_profile' => 'claude-opus-5-5']))
            ->assertStatus(403)->assertJsonPath('code', 'claude_access_missing');
    }

    public function test_other_task_types_keep_server_side_model_selection(): void
    {
        [$user, $token] = $this->token();
        $this->releaseModes();
        $this->personalCredential($user);
        $openrouter = ProviderCredential::create(['provider' => 'openrouter', 'label' => 'OR', 'api_key' => 'fake-openrouter-key', 'request_format' => 'chat_completions', 'active' => true]);
        $profile = ModelProfile::create([
            'name' => 'Chat', 'slug' => 'chat-default', 'provider' => 'openrouter', 'model_id' => 'meta/llama', 'purpose' => 'chat',
            'temperature' => 0.1, 'max_tokens' => 100, 'provider_credential_id' => $openrouter->id,
        ]);
        ProviderPriceSnapshot::create(['provider_id' => 'openrouter', 'model_id' => 'meta/llama', 'currency' => 'USD', 'input_per_million' => 1, 'output_per_million' => 2, 'source' => 'test', 'valid_from' => now()->subMinute()]);
        $chat = ModelUseCase::create(['name' => 'Chat', 'slug' => 'chat', 'active' => true, 'network_policy_key' => 'proxy.test.default', 'max_attempts' => 1]);
        ModelUseCaseEntry::create(['model_use_case_id' => $chat->id, 'model_profile_id' => $profile->id, 'sort_order' => 1, 'active' => true]);
        NetworkPolicy::create([
            'key' => 'proxy.test.default', 'name' => 'Default', 'status' => 'active', 'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000,
            'max_attempts' => 1, 'backoff_ms' => 0, 'max_input_tokens' => 1000, 'max_output_tokens' => 50,
            'config' => ['retry_statuses' => [0, 429, 500]],
        ]);

        $this->fakeProvider(function (string $method, string $url, array $options): Response {
            $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $url);
            $this->assertSame('Bearer fake-openrouter-key', $options['headers']['Authorization']);
            $this->assertSame('meta/llama', $options['json']['model']);
            // Claude-only request fields are ignored outside chat.main.
            $this->assertArrayNotHasKey('effort', $options['json']);
            $this->assertArrayNotHasKey('provider_fallback', $options['json']);
            $this->assertArrayNotHasKey('main_model_profile', $options['json']);

            return new Response(200, [], json_encode([
                'id' => 'or_1', 'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'OK']]],
                'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4],
            ], JSON_THROW_ON_ERROR));
        });

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', [
            'messages' => [['role' => 'user', 'content' => 'Hallo']],
            'task_type' => 'chat.general', 'main_model_profile' => 'claude-opus-5-5', 'effort' => 'max',
        ])->assertOk()
            ->assertHeader('X-Luczor-Provider', 'openrouter')
            ->assertHeader('X-Luczor-Use-Case', 'chat')
            ->assertJsonPath('choices.0.message.content', 'OK');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function mainRequest(array $overrides = []): array
    {
        return $overrides + [
            'messages' => [['role' => 'user', 'content' => 'Hallo Claude']],
            'task_type' => 'chat.main',
        ];
    }

    private function releaseModes(): void
    {
        app(ClaudeAccessService::class)->putSetting(ClaudeAccessService::SETTING_MODES_ALLOWED, true);
    }

    private function personalCredential(User $user): ProviderCredential
    {
        return ProviderCredential::create([
            'user_id' => $user->id, 'provider' => 'anthropic', 'label' => 'Persönlicher Claude-Zugang',
            'api_key' => self::PERSONAL_KEY, 'request_format' => 'messages', 'active' => true,
        ]);
    }

    private function organizationCredential(): ProviderCredential
    {
        return ProviderCredential::create([
            'provider' => 'anthropic', 'label' => 'Organisation', 'api_key' => self::ORG_KEY,
            'request_format' => 'messages', 'active' => true,
        ]);
    }

    /** @param  callable(string, string, array<string,mixed>): Response  $handler */
    private function fakeProvider(callable $handler): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturnUsing($handler);
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);
    }

    private function forbidProviderCalls(): void
    {
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldNotReceive('make');
        $this->app->instance(ProviderHttpClientFactory::class, $factory);
    }

    /**
     * @param  array<int,string>  $abilities
     * @return array{0: User, 1: string}
     */
    private function token(array $abilities = ['proxy.use']): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Desktop', 'abilities' => $abilities, 'active' => true]);

        return [$user, $minted['plain']];
    }
}
