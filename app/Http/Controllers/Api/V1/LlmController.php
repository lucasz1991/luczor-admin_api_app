<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ModelProfile;
use App\Models\ModelRanking;
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
}
