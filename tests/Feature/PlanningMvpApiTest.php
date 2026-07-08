<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\ApiKey;
use App\Models\ContextArtifact;
use App\Models\EvaluationResult;
use App\Models\LlmRun;
use App\Models\MemoryLink;
use App\Models\ModelRanking;
use App\Models\User;
use App\Services\LuczorMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_agent_run_can_create_tasks_and_update_status(): void
    {
        [, $token] = $this->token(['brain.write', 'brain.read']);

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
        [, $token] = $this->token(['brain.write', 'brain.read']);

        $run = LlmRun::create([
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

        $this->withHeader('X-Api-Key', $token)->postJson("/api/v1/llm/runs/{$run->id}/evaluate", [
            'status' => 'passed',
            'quality_score' => 0.8,
            'test_passed' => true,
            'test_pass_rate' => 1.0,
            'notes' => 'Tests bestanden.',
        ])->assertCreated()->assertJsonPath('data.quality_score', 0.8);

        $this->assertSame(1, EvaluationResult::count());
        $this->assertTrue($run->fresh()->test_passed);
        $this->assertDatabaseHas('model_rankings', [
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
