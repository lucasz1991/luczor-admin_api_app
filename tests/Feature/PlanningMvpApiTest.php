<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\ApiKey;
use App\Models\ContextArtifact;
use App\Models\EvaluationResult;
use App\Models\LlmAttempt;
use App\Models\LlmRun;
use App\Models\MemoryLink;
use App\Models\ModelRanking;
use App\Models\PerformanceProfile;
use App\Models\User;
use App\Services\GraphContextService;
use App\Services\LuczorMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlanningMvpApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_ask_returns_budgeted_memory_code_and_persists_artifact(): void
    {
        [$user, $token] = $this->token(['brain.read']);
        $dataset = app(LuczorMemoryService::class)->datasetFor('project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]);

        MemoryLink::create([
            'user_id' => $user->id,
            'client_id' => 'desktop-a',
            'external_id' => 'm1',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'feature_key' => 'memory.context',
            'type' => 'decision',
            'visibility' => 'syncable',
            'staleness' => 'fresh',
            'importance' => 0.9,
            'summary' => 'LuczorMemoryService bleibt die Memory-Fassade.',
            'meta' => ['file_path' => 'admin_api_app/app/Services/LuczorMemoryService.php'],
        ]);

        $res = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/context/ask', [
            'project_id' => 'p1',
            'task_type' => 'coding.fix_bug',
            'feature_key' => 'memory.context',
            'query' => 'LuczorMemoryService prüfen',
            'changed_files' => ['admin_api_app/app/Services/LuczorMemoryService.php'],
            'budget' => ['max_input_tokens' => 200, 'max_items' => 3],
        ]);

        $res->assertOk()
            ->assertJsonPath('task_type', 'coding.fix_bug')
            ->assertJsonPath('code.0.path', 'admin_api_app/app/Services/LuczorMemoryService.php')
            ->assertJsonPath('memory.0.id', 'm1')
            ->assertJsonPath('source_status.fallback', 'git_or_query');

        $this->assertSame(1, ContextArtifact::count());
        $this->assertSame('p1', ContextArtifact::first()->project_id);
    }

    public function test_server_graph_sidecar_is_never_called_even_when_legacy_config_is_present(): void
    {
        config([
            'luczor.graphify.base_url' => 'http://graph-indexer:8010',
            'luczor.graphify.api_key' => 'internal-test-key',
        ]);
        Http::fake();

        $result = app(GraphContextService::class)->resolve([
            'user_id' => 1,
            'query' => 'Graph prüfen',
            'code_limit' => 3,
            'code' => [[
                'path' => 'app/Services/MemoryOrchestrator.php',
                'reason' => 'symbol_exact',
                'score' => 0.95,
                'meta' => ['content_hash' => str_repeat('a', 64)],
            ]],
        ], []);

        $this->assertSame('disabled_local_only', $result['source_status']['server_graph']);
        $this->assertSame('app/Services/MemoryOrchestrator.php', $result['code'][0]['path']);
        $this->assertTrue($result['code'][0]['transient']);
        $this->assertSame([], $result['persistent_code']);
        Http::assertNothingSent();
    }

    public function test_local_graph_hints_reject_absolute_paths_and_code_payloads(): void
    {
        config([
            'luczor.graphify.base_url' => 'http://graph-indexer:8010',
            'luczor.graphify.api_key' => '',
        ]);
        Http::fake();

        $result = app(GraphContextService::class)->resolve([
            'query' => 'Graph prüfen',
            'code_limit' => 3,
            'code' => [[
                'path' => 'E:/private/repository/.env',
                'content' => 'SECRET=value',
                'meta' => ['snippet' => 'SECRET=value'],
            ]],
        ], []);

        $this->assertSame('not_provided', $result['source_status']['local_graph']);
        $this->assertSame([], $result['code']);
        Http::assertNothingSent();
    }

    public function test_local_graph_hints_are_returned_but_never_persisted_in_context_artifacts(): void
    {
        [, $token] = $this->token(['brain.read']);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/context/ask', [
            'project_id' => 'p1',
            'task_type' => 'coding.review',
            'query' => 'Welche Klasse ist relevant?',
            'code' => [[
                'path' => 'app/Services/MemoryOrchestrator.php',
                'reason' => 'symbol_exact',
                'score' => 0.98,
                'meta' => ['evidence_id' => 'ev1', 'content_hash' => str_repeat('b', 64)],
            ]],
        ])->assertOk()->assertJsonPath('code.0.path', 'app/Services/MemoryOrchestrator.php');

        $artifact = ContextArtifact::firstOrFail();
        $this->assertSame([], $artifact->code);
        $this->assertStringNotContainsString('MemoryOrchestrator.php', json_encode($artifact->toArray(), JSON_THROW_ON_ERROR));

        // The one-turn consent must not poison the reusable context cache.
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/context/ask', [
            'project_id' => 'p1',
            'task_type' => 'coding.review',
            'query' => 'Welche Klasse ist relevant?',
        ])->assertOk()->assertJsonCount(0, 'code');
    }

    public function test_agent_run_can_create_tasks_and_update_status(): void
    {
        [$user, $token] = $this->token(['brain.write', 'brain.read']);

        $runId = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/agent-runs', [
            'client_id' => 'desktop-a',
            'project_id' => 'p1',
            'task_type' => 'planning.architecture',
            'goal' => 'Masterplan-MVP umsetzen',
        ])->assertCreated()->json('data.id');

        $this->withHeader('X-Api-Key', $token)->postJson("/api/v1/agent-runs/{$runId}/tasks", [
            'title' => 'Evaluator anbinden',
            'status' => 'in_progress',
            'acceptance_criteria' => ['Feature-Test grün'],
        ])->assertCreated()->assertJsonPath('data.status', 'in_progress');

        $this->withHeader('X-Api-Key', $token)->patchJson("/api/v1/agent-runs/{$runId}", [
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(1, AgentRun::first()->tasks()->count());
        $this->assertNotNull(AgentRun::first()->finished_at);
    }

    public function test_llm_evaluation_updates_run_and_rankings(): void
    {
        [$user, $token] = $this->token(['brain.write', 'brain.read']);

        $run = LlmRun::create([
            'user_id' => $user->id,
            'project_id' => 'p1',
            'task_type' => 'coding.fix_bug',
            'model_id' => '@preset/luczor',
            'provider_id' => 'openrouter',
            'status' => 'ok',
            'success' => true,
            'latency_ms' => 1000,
            'input_tokens' => 800,
            'output_tokens' => 120,
            'cost_total' => 0.001,
        ]);
        LlmAttempt::create([
            'llm_run_id' => $run->id,
            'attempt_no' => 1,
            'provider_id' => 'openrouter',
            'model_id' => '@preset/luczor',
            'status' => 'completed',
            'http_status' => 200,
            'total_ms' => 1000,
            'input_tokens' => 800,
            'output_tokens' => 120,
            'effective_cost' => 0.001,
        ]);

        $this->withHeader('X-Api-Key', $token)->postJson("/api/v1/llm/runs/{$run->id}/evaluate", [
            'status' => 'passed',
            'quality_score' => 0.8,
            'test_passed' => true,
            'test_pass_rate' => 1.0,
            'notes' => 'Tests bestanden.',
        ])->assertCreated()->assertJsonPath('data.quality_score', 0.8);

        $this->assertSame(1, EvaluationResult::count());
        $this->assertSame(1, PerformanceProfile::count());
        $this->assertTrue($run->fresh()->test_passed);
        $this->assertDatabaseHas('model_rankings', [
            'user_id' => $user->id,
            'task_type' => 'coding.fix_bug',
            'model_id' => '@preset/luczor',
        ]);
        $this->assertGreaterThan(0.7, ModelRanking::first()->score);
    }

    /** @return array{0: User, 1: string} */
    private function token(array $abilities): array
    {
        $user = User::factory()->create();
        $minted = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Test Device',
            'abilities' => $abilities,
            'active' => true,
        ]);

        return [$user, $minted['plain']];
    }
}
