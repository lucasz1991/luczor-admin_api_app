<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ContextController as ContextService;
use Illuminate\Http\Request;

class ContextController extends Controller
{
    public function ask(Request $request, ContextService $context)
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:2000'],
            'task_type' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'feature_key' => ['nullable', 'string', 'max:160'],
            'budget' => ['nullable', 'array'],
            'budget.max_input_tokens' => ['nullable', 'integer', 'min:100', 'max:8000'],
            'budget.max_items' => ['nullable', 'integer', 'min:1', 'max:20'],
            'repo_id' => ['nullable', 'string', 'max:120'],
            'repo_path' => ['nullable', 'string', 'max:1000'],
            'branch' => ['nullable', 'string', 'max:160'],
            'commit_sha' => ['nullable', 'string', 'max:80'],
            'changed_files' => ['nullable', 'array'],
            'changed_files.*' => ['string', 'max:500'],
            'code' => ['nullable', 'array'],
            'code_limit' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        return response()->json(
            $context->ask(array_merge($data, ['user_id' => $request->user()?->id]))
        );
    }
}
