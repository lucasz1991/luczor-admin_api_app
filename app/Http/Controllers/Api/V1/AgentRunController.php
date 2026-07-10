<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Services\ApiActor;
use Illuminate\Http\Request;

class AgentRunController extends Controller
{
    public function store(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'workflow_id' => ['nullable', 'string', 'max:120'],
            'task_id' => ['nullable', 'string', 'max:120'],
            'session_id' => ['nullable', 'string', 'max:120'],
            'task_type' => ['nullable', 'string', 'max:120'],
            'feature_key' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', 'max:40'],
            'context_id' => ['nullable', 'string', 'max:120'],
            'goal' => ['nullable', 'string', 'max:8000'],
            'meta' => ['nullable', 'array'],
        ]);

        $project = $actor->project($request, $data['project_id'] ?? null);
        $run = AgentRun::create(array_merge($data, [
            'user_id' => $actor->userId($request),
            'client_id' => $actor->deviceId($request, $data['client_id'] ?? null),
            'project_ref_id' => $project?->id,
            'task_type' => $data['task_type'] ?? 'chat.general',
            'status' => $data['status'] ?? 'queued',
            'started_at' => in_array($data['status'] ?? null, ['running', 'completed', 'failed'], true) ? now() : null,
        ]));

        return response()->json(['data' => $run->load('tasks')], 201);
    }

    public function show(Request $request, AgentRun $agentRun, ApiActor $actor)
    {
        $actor->assertOwned($request, $agentRun);
        return response()->json(['data' => $agentRun->load(['tasks', 'evaluations'])]);
    }

    public function update(Request $request, AgentRun $agentRun, ApiActor $actor)
    {
        $actor->assertOwned($request, $agentRun);
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'context_id' => ['nullable', 'string', 'max:120'],
            'meta' => ['nullable', 'array'],
        ]);

        if (($data['status'] ?? null) === 'running' && ! $agentRun->started_at) {
            $data['started_at'] = now();
        }
        if (in_array($data['status'] ?? null, ['completed', 'failed', 'cancelled'], true)) {
            $data['finished_at'] = now();
        }

        $agentRun->fill($data)->save();

        return response()->json(['data' => $agentRun->load('tasks')]);
    }

    public function storeTask(Request $request, AgentRun $agentRun, ApiActor $actor)
    {
        $actor->assertOwned($request, $agentRun);
        $data = $request->validate([
            'task_id' => ['nullable', 'string', 'max:120'],
            'task_type' => ['nullable', 'string', 'max:120'],
            'feature_key' => ['nullable', 'string', 'max:160'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:8000'],
            'status' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'acceptance_criteria' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
        ]);

        $task = $agentRun->tasks()->create(array_merge($data, [
            'user_id' => $agentRun->user_id,
            'project_id' => $agentRun->project_id,
            'project_ref_id' => $agentRun->project_ref_id,
            'task_type' => $data['task_type'] ?? $agentRun->task_type,
            'feature_key' => $data['feature_key'] ?? $agentRun->feature_key,
            'status' => $data['status'] ?? 'open',
            'priority' => $data['priority'] ?? 100,
        ]));

        return response()->json(['data' => $task], 201);
    }
}
