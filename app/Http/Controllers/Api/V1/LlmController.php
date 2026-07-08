<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ModelProfile;
use App\Models\ModelRanking;
use App\Models\LlmRun;
use App\Services\EvaluationService;
use App\Services\ModelRanker;
use Illuminate\Http\Request;

class LlmController extends Controller
{
    /** Choose the best model for a task type (exploit ~90%, explore ~10%). */
    public function route(Request $request, ModelRanker $ranker)
    {
        $data = $request->validate([
            'task_type' => ['nullable', 'string', 'max:120'],
        ]);
        $taskType = $data['task_type'] ?? 'chat.general';

        $ranker->recompute($taskType);
        $rankings = ModelRanking::query()->where('task_type', $taskType)->orderByDesc('score')->get();

        if ($rankings->isNotEmpty()) {
            $explore = $rankings->count() > 1 && random_int(1, 100) <= 10;
            $chosen = $explore ? $rankings->slice(1)->random() : $rankings->first();

            return response()->json([
                'task_type' => $taskType,
                'model_id' => $chosen->model_id,
                'provider' => $chosen->provider_id,
                'source' => $explore ? 'exploration' : 'ranking',
                'score' => $chosen->score,
                'sample_count' => $chosen->sample_count,
            ]);
        }

        $slug = config('luczor.default_model_profile');
        $profile = ModelProfile::query()->where('slug', $slug)->where('active', true)->first()
            ?? ModelProfile::query()->where('active', true)->first();

        return response()->json([
            'task_type' => $taskType,
            'model_id' => $profile?->model_id ?? '@preset/luczor',
            'provider' => $profile?->provider ?? 'openrouter',
            'source' => 'default',
        ]);
    }

    public function rankings(Request $request, ModelRanker $ranker)
    {
        $taskType = $request->query('task_type');
        $ranker->recompute($taskType ?: null);

        $query = ModelRanking::query()->orderBy('task_type')->orderByDesc('score');
        if ($taskType) {
            $query->where('task_type', $taskType);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function evaluate(Request $request, LlmRun $llmRun, EvaluationService $evaluator)
    {
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

        $result = $evaluator->evaluateRun($llmRun, $data);

        return response()->json(['data' => $result->load('llmRun')], 201);
    }
}
