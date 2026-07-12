<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\ApiActor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** SOLL §8 — agent/user task management (create, assign, complete). */
class TaskController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:open,in_progress,done,cancelled'],
            'project_id' => ['nullable', 'string', 'max:190'],
            'conversation_id' => ['nullable', 'string', 'max:190'],
        ]);

        $query = Task::where('user_id', $request->user()->id);
        if (! empty($data['status'])) $query->where('status', $data['status']);
        if (! empty($data['conversation_id'])) $query->where('conversation_id', $data['conversation_id']);
        if (! empty($data['project_id'])) {
            $project = app(ApiActor::class)->project($request, $data['project_id']);
            $query->where('project_ref_id', $project?->id);
        }

        return response()->json(['data' => $query->orderByDesc('id')->limit(200)->get()]);
    }

    public function store(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'priority' => ['nullable', 'string', 'in:low,normal,high'],
            'project_id' => ['nullable', 'string', 'max:190'],
            'conversation_id' => ['nullable', 'string', 'max:190'],
            'due_at' => ['nullable', 'date'],
            'client_id' => ['nullable', 'string', 'max:120'],
        ]);

        $project = $actor->project($request, $data['project_id'] ?? null);

        $task = Task::create([
            'user_id' => $request->user()->id,
            'client_id' => $data['client_id'] ?? 'server',
            'external_id' => (string) Str::uuid(),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => 'open',
            'priority' => $data['priority'] ?? 'normal',
            'project_ref_id' => $project?->id,
            'conversation_id' => $data['conversation_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
        ]);

        return response()->json(['data' => $task], 201);
    }

    public function update(Request $request, string $externalId, ApiActor $actor)
    {
        $task = Task::where('user_id', $request->user()->id)
            ->where('external_id', $externalId)->firstOrFail();

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'status' => ['nullable', 'string', 'in:open,in_progress,done,cancelled'],
            'priority' => ['nullable', 'string', 'in:low,normal,high'],
            'project_id' => ['nullable', 'string', 'max:190'],
            'conversation_id' => ['nullable', 'string', 'max:190'],
        ]);

        if (array_key_exists('project_id', $data)) {
            $task->project_ref_id = $actor->project($request, $data['project_id'])?->id;
        }
        foreach (['title', 'description', 'status', 'priority', 'conversation_id'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) $task->{$field} = $data[$field];
        }
        if (($data['status'] ?? null) === 'done' && ! $task->completed_at) {
            $task->completed_at = now();
        }
        if (($data['status'] ?? null) && $data['status'] !== 'done') {
            $task->completed_at = null;
        }
        $task->save();

        return response()->json(['data' => $task]);
    }
}
