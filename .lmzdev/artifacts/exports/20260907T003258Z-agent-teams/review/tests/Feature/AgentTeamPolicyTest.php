<?php

namespace Tests\Feature;

use App\Data\Proxy\ProxyChatInput;
use App\Models\AgentProfile;
use App\Models\ApiKey;
use App\Models\EvaluationResult;
use App\Models\LlmAttempt;
use App\Models\LlmRun;
use App\Models\ModelProfile;
use App\Models\ModelRanking;
use App\Models\ModelUseCase;
use App\Models\NetworkPolicy;
use App\Models\ProviderCredential;
use App\Models\ProviderPriceSnapshot;
use App\Models\User;
use App\Services\AgentTeamDefaultsService;
use App\Services\AgentTeamPolicyService;
use App\Services\EvaluationService;
use App\Services\ProviderHttpClientFactory;
use App\Services\ProviderPolicyService;
use App\Services\Proxy\ProxyPromptBuilder;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class AgentTeamPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 7)->setTime(10, 0));
    }

    public function test_discovery_is_read_only_and_requires_settings_scope(): void
    {
        $this->getJson('/api/v1/agent-team-policy')->assertUnauthorized();
        $this->withHeader('X-Api-Key', $this->token(['brain.read']))->getJson('/api/v1/agent-team-policy')->assertForbidden();
        $this->withHeader('X-Api-Key', $this->token(['settings.read']))->getJson('/api/v1/agent-team-policy')
            ->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('default_preset', 'free')
            ->assertJsonPath('presets.0.roles.planning.target', 'local')
            ->assertJsonPath('presets.1.roles.planning.task_type', 'agent.planning')
            ->assertJsonPath('evaluation.minimum_samples', 5)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('agent_profiles', 0);
    }

    public function test_defaults_are_idempotent_and_do_not_overwrite_custom_or_disabled_routes(): void
    {
        $credential = $this->credential();
        $service = app(AgentTeamDefaultsService::class);
        $first = $service->prepare($credential->id);
        $this->assertSame(4, $first['roles_created']);
        $model = ModelProfile::firstOrFail();
        $model->update(['name' => 'Eigene Auswahl', 'active' => false]);
        $case = ModelUseCase::where('slug', 'agent-coding')->firstOrFail();
        $case->entries()->delete();
        $case->update(['routing_strategy' => 'manual', 'active' => false]);
        NetworkPolicy::where('key', 'agent.free')->update(['max_attempts' => 1]);
        AgentProfile::where('key', AgentTeamPolicyService::POLICY_KEY)->update(['status' => 'disabled']);

        $this->assertSame(['models_created' => 0, 'roles_created' => 0, 'entries_created' => 0], $service->prepare($credential->id));
        $this->assertSame('Eigene Auswahl', $model->fresh()->name);
        $this->assertFalse($model->fresh()->active);
        $this->assertFalse($case->fresh()->active);
        $this->assertSame(0, $case->entries()->count());
        $this->assertFalse(app(AgentTeamPolicyService::class)->payload()['enabled']);
        $this->assertEquals(1, NetworkPolicy::where('key', 'agent.free')->value('max_attempts'));
    }

    public function test_roles_have_distinct_routing_and_never_use_paid_fallback_for_free_specialists(): void
    {
        app(AgentTeamDefaultsService::class)->prepare($this->credential()->id);
        $policy = app(ProviderPolicyService::class);
        $coding = $policy->resolve('agent.coding', [], ['messages' => [['role' => 'user', 'content' => 'Codeentwurf']]]);
        $this->assertSame('agent-coding', $coding->useCase->slug);
        $this->assertSame('cohere/north-mini-code:free', $coding->profiles[0]->model_id);
        $this->assertSame(2, $coding->maxAttempts);
        $this->assertEquals(0, $coding->reservedCostUsd);
        foreach ($coding->profiles as $profile) {
            $this->assertStringEndsWith(':free', $profile->model_id);
        }
        $planning = $policy->resolve('agent.planning', [], ['messages' => [['role' => 'user', 'content' => 'Plan']]]);
        $this->assertSame('deepseek/deepseek-v4-flash-0731', $planning->profiles[0]->model_id);
        $this->assertEquals(0.05, $planning->maxCostUsd);
        $this->assertNull($policy->useCaseFor('agent.unknown'));
        $this->assertNull($policy->useCaseFor('chat.general'));
        AgentProfile::where('key', AgentTeamPolicyService::POLICY_KEY)->update(['status' => 'disabled']);
        $this->assertNull($policy->useCaseFor('agent.coding'));
    }

    public function test_api_only_exposes_public_model_information_and_hides_credentials(): void
    {
        app(AgentTeamDefaultsService::class)->prepare($this->credential()->id);
        $response = $this->withHeader('X-Api-Key', $this->token(['settings.read']))->getJson('/api/v1/agent-team-policy')
            ->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('models_by_role.coding.ready', true)
            ->assertJsonPath('models_by_role.coding.candidates.0.id', 'cohere/north-mini-code:free');
        $this->assertStringNotContainsString('secret-test-provider-key', $response->getContent());
        $this->assertStringNotContainsString('provider_credential_id', $response->getContent());
    }

    public function test_public_research_uses_no_auth_and_excludes_missing_or_no_longer_free_candidates(): void
    {
        $this->credential();
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => [
            ['id' => 'cohere/north-mini-code:free', 'context_length' => 200000, 'pricing' => ['prompt' => '0', 'completion' => '0']],
            ['id' => 'poolside/laguna-xs-2.1:free', 'context_length' => 200000, 'pricing' => ['prompt' => '0.001', 'completion' => '0']],
            ['id' => 'unreviewed/model:free', 'context_length' => 200000, 'pricing' => ['prompt' => '0', 'completion' => '0']],
        ]])]);
        $catalog = app(AgentTeamDefaultsService::class)->refreshResearch();
        $this->assertCount(1, $catalog['models']);
        $this->assertSame('cohere/north-mini-code:free', $catalog['models'][0]['id']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && ! $request->hasHeader('Authorization'));
        $this->assertDatabaseCount('model_profiles', 0);
    }

    public function test_unavailable_public_catalog_preserves_the_last_research_and_shows_a_form_error(): void
    {
        $catalog = config('agent_teams');
        AgentProfile::create(['key' => AgentTeamPolicyService::CATALOG_KEY, 'name' => 'Research', 'type' => 'model_catalog', 'status' => 'draft', 'config' => $catalog]);
        Http::fake(['openrouter.ai/api/v1/models' => Http::response([], 503)]);
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin)->from(route('admin.page', 'agents'))->post(route('dashboard.agent-teams.research'))
            ->assertRedirect(route('admin.page', 'agents'))->assertSessionHasErrors('research');
        $this->assertSame($catalog, app(AgentTeamPolicyService::class)->catalog());
    }

    public function test_agent_proxy_rejects_tools_and_executable_history_before_any_provider_call(): void
    {
        $this->credential();
        $token = $this->token(['proxy.use']);
        $base = ['task_type' => 'agent.coding', 'messages' => [['role' => 'user', 'content' => 'Codeentwurf']]];
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $base + ['tools' => [['type' => 'function']]])
            ->assertUnprocessable()->assertJsonValidationErrors('tools');
        $base['messages'][] = ['role' => 'tool', 'tool_call_id' => 'a', 'content' => 'private results'];
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $base)
            ->assertUnprocessable()->assertJsonValidationErrors('messages.1');
        $this->assertDatabaseCount('llm_attempts', 0);
    }

    public function test_text_specialists_do_not_forward_empty_tool_contracts(): void
    {
        $input = ProxyChatInput::fromValidated(['task_type' => 'agent.review', 'messages' => [['role' => 'user', 'content' => 'Prüfe diesen Entwurf.']], 'tools' => [], 'tool_choice' => 'none']);
        $prepared = app(ProxyPromptBuilder::class)->prepare($input, []);
        $this->assertArrayNotHasKey('tools', $prepared->payload);
        $this->assertArrayNotHasKey('tool_choice', $prepared->payload);
    }

    public function test_policy_revision_binds_cost_limits_models_and_provider_changes(): void
    {
        $credential = $this->credential();
        app(AgentTeamDefaultsService::class)->prepare($credential->id);
        $service = app(AgentTeamPolicyService::class);
        $before = $service->payload();
        $this->assertSame($before['revision'], $service->payload()['revision']);
        $this->assertEquals(0.05, $before['models_by_role']['planning']['max_cost_usd']);
        $this->assertSame(4096, $before['models_by_role']['planning']['max_output_tokens']);
        NetworkPolicy::where('key', 'agent.planning')->update(['max_cost_usd' => 0.1]);
        $changed = $service->payload()['revision'];
        $this->assertNotSame($before['revision'], $changed);
        $credential->update(['base_url' => 'https://openrouter.ai/api/changed']);
        $this->assertNotSame($changed, $service->payload()['revision']);
    }

    public function test_stale_or_missing_policy_revision_never_dispatches_a_provider_request(): void
    {
        app(AgentTeamDefaultsService::class)->prepare($this->credential()->id);
        $base = ['task_type' => 'agent.coding', 'messages' => [['role' => 'user', 'content' => 'Entwurf']]];
        $token = $this->token(['proxy.use']);
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $base)->assertUnprocessable()->assertJsonValidationErrors('agent_team_policy_revision');
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/proxy/chat', $base + ['agent_team_policy_revision' => str_repeat('0', 64)])
            ->assertStatus(409)->assertJsonPath('code', 'agent_team_policy_changed');
        $this->assertDatabaseCount('llm_attempts', 0);
    }

    public function test_free_specialist_proxy_sends_a_text_only_price_capped_provider_request(): void
    {
        app(AgentTeamDefaultsService::class)->prepare($this->credential()->id);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function (string $method, string $url, array $options): bool {
            $this->assertSame('cohere/north-mini-code:free', $options['json']['model']);
            $this->assertArrayNotHasKey('tools', $options['json']);
            $this->assertArrayNotHasKey('tool_choice', $options['json']);
            $this->assertEquals(['prompt' => 0, 'completion' => 0, 'request' => 0], $options['json']['provider']['max_price']);
            $this->assertSame(4096, $options['json']['max_tokens']);

            return true;
        })->andReturn(new Response(200, ['Content-Type' => 'application/json'], json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Entwurf'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3]])));
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);
        $this->withHeader('X-Api-Key', $this->token(['proxy.use']))->postJson('/api/v1/proxy/chat', [
            'task_type' => 'agent.coding', 'agent_team_policy_revision' => app(AgentTeamPolicyService::class)->payload()['revision'],
            'messages' => [['role' => 'user', 'content' => 'Entwurf']], 'tools' => [], 'tool_choice' => 'none',
        ])->assertOk()->assertHeader('X-Luczor-Request-Id');
        $this->assertDatabaseHas('llm_runs', ['task_type' => 'agent.coding', 'model_id' => 'cohere/north-mini-code:free']);
    }

    public function test_changed_policy_between_free_fallbacks_prevents_the_second_dispatch(): void
    {
        app(AgentTeamDefaultsService::class)->prepare($this->credential()->id);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturnUsing(function (): Response {
            NetworkPolicy::where('key', 'agent.free')->update(['max_output_tokens' => 1000]);

            return new Response(429, ['Content-Type' => 'application/json'], '{"error":"limited"}');
        });
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);
        $this->withHeader('X-Api-Key', $this->token(['proxy.use']))->postJson('/api/v1/proxy/chat', [
            'task_type' => 'agent.coding', 'agent_team_policy_revision' => app(AgentTeamPolicyService::class)->payload()['revision'],
            'messages' => [['role' => 'user', 'content' => 'Entwurf']],
        ])->assertStatus(409)->assertJsonPath('code', 'agent_team_policy_changed');
        $this->assertDatabaseCount('llm_attempts', 1);
    }

    public function test_ranked_selection_requires_five_distinct_evaluations_not_http_success_or_duplicate_reviews(): void
    {
        app(AgentTeamDefaultsService::class)->prepare($this->credential()->id);
        $case = ModelUseCase::where('slug', 'agent-coding')->firstOrFail();
        $profiles = $case->entries()->with('modelProfile')->get()->pluck('modelProfile');
        $first = $profiles[0];
        $challenger = $profiles[1];
        foreach ([$first, $challenger] as $model) {
            ModelRanking::create(['task_type' => 'agent.coding', 'model_profile_id' => $model->id, 'model_id' => $model->model_id, 'provider_id' => 'openrouter', 'sample_count' => 10, 'score' => $model->id === $challenger->id ? 0.99 : 0.1]);
        }
        $policy = app(ProviderPolicyService::class);
        $this->assertSame($first->id, $policy->candidates(null, 'agent.coding')[0]->id);
        $runs = [];
        for ($i = 0; $i < 5; $i++) {
            $run = LlmRun::create(['request_id' => (string) Str::uuid(), 'task_type' => 'agent.coding', 'model_id' => $challenger->model_id, 'provider_id' => 'openrouter', 'status' => 'ok', 'success' => true]);
            LlmAttempt::create(['llm_run_id' => $run->id, 'model_profile_id' => $challenger->id, 'model_id' => $challenger->model_id, 'provider_id' => 'openrouter', 'attempt_no' => 1, 'status' => 'completed']);
            $runs[] = $run;
        }
        foreach (range(1, 5) as $ignored) {
            EvaluationResult::create(['llm_run_id' => $runs[0]->id, 'evaluator_id' => 'human', 'status' => 'passed', 'quality_score' => 0.9]);
        }
        $this->assertSame($first->id, $policy->candidates(null, 'agent.coding')[0]->id);
        foreach (array_slice($runs, 1) as $run) {
            EvaluationResult::create(['llm_run_id' => $run->id, 'evaluator_id' => 'human', 'status' => 'passed', 'quality_score' => 0.9]);
        }
        $this->assertSame($challenger->id, $policy->candidates(null, 'agent.coding')[0]->id);
    }

    public function test_admin_can_configure_teams(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin)->get(route('admin.page', 'agents'))->assertOk()->assertSee('Agententeams und Modellrecherche')->assertSee('Noch keine Rollenmessungen');
        $this->actingAs($admin)->put(route('dashboard.agent-teams.update'), ['enabled' => true, 'default_preset' => 'budget', 'max_parallel' => 3])->assertRedirect();
        $payload = app(AgentTeamPolicyService::class)->payload();
        $this->assertSame('budget', $payload['default_preset']);
        $this->assertSame(3, $payload['presets'][0]['max_parallel']);
        $this->actingAs($admin)->put(route('dashboard.agent-teams.update'), ['enabled' => true, 'default_preset' => 'arbitrary', 'max_parallel' => 100])->assertSessionHasErrors(['default_preset', 'max_parallel']);
    }

    public function test_non_admin_cannot_configure_teams(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->post(route('dashboard.agent-teams.prepare'), ['provider_credential_id' => $this->credential()->id])->assertForbidden();
    }

    public function test_expired_research_cannot_seed_routes(): void
    {
        $credential = $this->credential();
        app(AgentTeamDefaultsService::class)->prepare($credential->id);
        $price = ProviderPriceSnapshot::firstOrFail();
        $this->assertNotNull($price->valid_until);
        $this->travel(15)->days();
        $this->expectException(ValidationException::class);
        app(AgentTeamDefaultsService::class)->prepare($credential->id);
    }

    public function test_agent_evaluations_require_evidence_and_cannot_label_untested_work_as_tested(): void
    {
        $user = User::factory()->create();
        $token = ApiKey::mint(['user_id' => $user->id, 'name' => 'Evaluation', 'abilities' => ['brain.write'], 'active' => true])['plain'];
        $run = LlmRun::create(['request_id' => (string) Str::uuid(), 'user_id' => $user->id, 'task_type' => 'agent.coding', 'model_id' => 'test/model', 'provider_id' => 'openrouter', 'status' => 'ok', 'success' => true]);
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/llm/runs/request/'.$run->request_id.'/evaluate', ['success_score' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('quality_score');
        $result = app(EvaluationService::class)->evaluateRun($run, ['evaluator_id' => 'desktop.agent-review.v1', 'quality_score' => 0.7, 'test_passed' => null, 'payload' => ['evidence_type' => 'model_review']]);
        $this->assertNull($result->test_pass_rate);
        $this->assertNull($run->fresh()->test_passed);
        $result = app(EvaluationService::class)->evaluateRun($run, ['evaluator_id' => 'test.runner', 'test_passed' => false]);
        $this->assertEquals(0, $result->quality_score);
        $this->assertSame('failed', $result->status);
        $this->assertFalse($run->fresh()->test_passed);
    }

    public function test_research_refresh_updates_managed_prices_but_preserves_admin_prices(): void
    {
        $credential = $this->credential();
        $service = app(AgentTeamDefaultsService::class);
        $service->prepare($credential->id);
        $adminPrice = ProviderPriceSnapshot::where('model_id', 'cohere/north-mini-code:free')->firstOrFail();
        $adminPrice->update(['source' => 'admin']);
        $previous = ProviderPriceSnapshot::where('model_id', 'deepseek/deepseek-v4-flash-0731')->firstOrFail();
        $catalog = config('agent_teams');
        $catalog['researched_at'] = now()->toIso8601String();
        foreach ($catalog['models'] as &$model) {
            if ($model['id'] === 'deepseek/deepseek-v4-flash-0731') {
                $model['input_per_million'] = 0.2;
            }
        }
        unset($model);
        AgentProfile::create(['key' => AgentTeamPolicyService::CATALOG_KEY, 'name' => 'Research', 'type' => 'model_catalog', 'status' => 'draft', 'config' => $catalog]);
        $service->prepare($credential->id);
        $this->assertSame($adminPrice->id, ProviderPriceSnapshot::current('openrouter', $adminPrice->model_id)->id);
        $fresh = ProviderPriceSnapshot::current('openrouter', $previous->model_id);
        $this->assertNotSame($previous->id, $fresh->id);
        $this->assertEquals(0.2, $fresh->input_per_million);
        $this->assertTrue($previous->fresh()->valid_until->lte(now()));
    }

    private function credential(): ProviderCredential
    {
        return ProviderCredential::create(['provider' => 'openrouter', 'label' => 'Agent tests', 'api_key' => 'secret-test-provider-key', 'request_format' => 'chat_completions', 'active' => true]);
    }

    /** @param list<string> $abilities */
    private function token(array $abilities): string
    {
        return ApiKey::mint(['user_id' => User::factory()->create()->id, 'name' => 'Team tests', 'abilities' => $abilities, 'active' => true])['plain'];
    }
}
