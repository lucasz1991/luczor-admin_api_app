<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\LlmExperiment;
use App\Models\LlmRun;
use App\Models\ModelRanking;
use App\Models\PromptTemplate;
use App\Services\ApiActor;
use App\Services\EvaluationService;
use App\Services\ModelRanker;
use App\Services\ProviderPolicyService;
use Illuminate\Http\Request;

class LlmController extends Controller
{
    /** Choose the best model for a task type (exploit ~90%, explore ~10%). */
    public function route(Request $request, ModelRanker $ranker, ApiActor $actor, ProviderPolicyService $policy)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $data = $request->validate([
            'task_type' => ['nullable', 'string', 'max:120'],
        ]);
        $taskType = $data['task_type'] ?? 'chat.general';

        $userId = $actor->userId($request);
        $ranker->recompute($taskType);
        $profile = $policy->candidates(null, $taskType)[0] ?? null;

        return response()->json([
            'task_type' => $taskType,
            'model_id' => $profile ? $profile->model_id : '@preset/luczor',
            'provider' => $profile ? $profile->provider : 'openrouter',
            'source' => 'admin_policy_and_metrics',
        ]);
    }

    public function rankings(Request $request, ModelRanker $ranker, ApiActor $actor)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $taskType = $request->query('task_type');
        $ranker->recompute($taskType ?: null);

        $query = ModelRanking::query()->whereNull('user_id')->orderBy('task_type')->orderByDesc('score');
        if ($taskType) {
            $query->where('task_type', $taskType);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function evaluate(Request $request, LlmRun $llmRun, EvaluationService $evaluator, ApiActor $actor)
    {
        $actor->assertOwned($request, $llmRun);
        $data = $request->validate([
            'agent_run_id' => ['nullable', 'integer', 'exists:agent_runs,id'],
            'evaluator_id' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'success_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'quality_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'test_passed' => ['nullable', 'boolean'],
            'test_pass_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'security_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'diff_quality_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'context_efficiency_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'hallucination_flags' => ['nullable', 'integer', 'min:0'],
            'user_feedback' => ['nullable', 'integer', 'min:-2', 'max:2'],
            'notes' => ['nullable', 'string', 'max:8000'],
            'payload' => ['nullable', 'array'],
        ]);

        if (! empty($data['agent_run_id'])) {
            $agentRun = AgentRun::findOrFail($data['agent_run_id']);
            $actor->assertOwned($request, $agentRun);
        }

        $result = $evaluator->evaluateRun($llmRun, $data);

        return response()->json(['data' => $result->load('llmRun')], 201);
    }

    public function evaluateByRequest(Request $request, string $requestId, EvaluationService $evaluator, ApiActor $actor)
    {
        $run = LlmRun::query()->where('request_id', $requestId)->firstOrFail();

        return $this->evaluate($request, $run, $evaluator, $actor);
    }

    public function runs(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return response()->json(['data' => LlmRun::query()->with(['attempts', 'metrics', 'evaluations'])->latest()->paginate(min(100, max(10, (int) $request->query('per_page', 25))))]);
    }

    public function telemetry(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $days = min(365, max(1, (int) $request->query('days', 30)));
        $query = LlmRun::query()->where('created_at', '>=', now()->subDays($days));
        $total = (clone $query)->count();
        $success = (clone $query)->where('success', true)->count();

        return response()->json(['data' => [
            'window_days' => $days, 'runs' => $total, 'success_rate' => $total ? $success / $total : 0,
            'total_cost' => (float) (clone $query)->sum('cost_total'),
            'cost_per_success' => $success ? (float) (clone $query)->sum('cost_total') / $success : null,
            'avg_latency_ms' => (float) (clone $query)->avg('latency_ms'), 'avg_ttft_ms' => (float) (clone $query)->avg('ttft_ms'),
            'avg_tokens_per_second' => (float) (clone $query)->avg('tokens_per_second'),
            'fallback_rate' => $total ? (clone $query)->where('attempt_count', '>', 1)->count() / $total : 0,
            'input_tokens' => (int) (clone $query)->sum('input_tokens'), 'output_tokens' => (int) (clone $query)->sum('output_tokens'),
        ]]);
    }

    public function experiments(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return response()->json(['data' => LlmExperiment::query()->latest()->get()]);
    }

    public function prompts(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return response()->json(['data' => PromptTemplate::query()->orderBy('key')->orderByDesc('version')->get()]);
    }
}
