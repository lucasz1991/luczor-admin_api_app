<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContextArtifact;
use App\Services\ApiActor;
use App\Services\ContextController as ContextService;
use App\Services\MemoryOrchestrator;
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

    public function code(Request $request, ContextService $context)
    {
        return $this->ask($request, $context);
    }

    public function impact(Request $request, ContextService $context)
    {
        return $this->ask($request, $context);
    }

    public function memory(Request $request, MemoryOrchestrator $memory)
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:2000'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json(['data' => $memory->recall($data['query'] ?? '', 'project', [
            'user_id' => $request->user()?->id,
            'tenant_id' => $request->user()?->tenant_id,
            'project_id' => $data['project_id'] ?? null,
        ], (int) ($data['limit'] ?? 6))]);
    }

    public function explain(Request $request, string $contextId, ApiActor $actor)
    {
        $artifact = ContextArtifact::query()->where('context_id', $contextId)->firstOrFail();
        $actor->assertOwned($request, $artifact);

        return response()->json(['data' => [
            'context_id' => $artifact->context_id,
            'budget' => $artifact->budget,
            'explain' => $artifact->score_explain,
            'source_status' => $artifact->source_status,
        ]]);
    }

    public function updateMemory(Request $request, MemoryOrchestrator $memory, ApiActor $actor)
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:8000'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'feature_key' => ['nullable', 'string', 'max:160'],
            'importance' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);
        $project = $actor->project($request, $data['project_id'] ?? null);
        $result = $memory->remember($data + [
            'user_id' => $actor->userId($request),
            'tenant_id' => $request->user()?->tenant_id,
            'project_ref_id' => $project?->id,
            'scope' => 'project',
            'visibility' => 'syncable',
            'type' => 'context_update',
            'write_intent' => 'confirmed',
            'source_type' => 'user',
        ]);

        return response()->json(['data' => $result->toArray()], 201);
    }
}
