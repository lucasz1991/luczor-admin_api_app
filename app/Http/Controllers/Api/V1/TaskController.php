<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\ApiActor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Services\WorkflowEventService;

/** SOLL §8 — agent/user task management (create, assign, complete). */
class TaskController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:open,in_progress,done,cancelled'],
            'project_id' => ['nullable', 'string', 'max:190'],
            'conversation_id' => ['nullable', 'string', 'max:190'],
            'external_id' => ['nullable', 'uuid'],
        ]);

        $query = Task::where('user_id', $request->user()->id);
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['conversation_id'])) {
            $query->where('conversation_id', $data['conversation_id']);
        }
        if (! empty($data['project_id'])) {
            $project = app(ApiActor::class)->project($request, $data['project_id']);
            if ($project) {
                $query->where('project_ref_id', $project->id);
            } else {
                // An unknown project filter must not match unassigned tasks.
                $query->whereRaw('1 = 0');
            }
        }
        if (! empty($data['external_id'])) {
            $query->where('external_id', $data['external_id']);
        }

        return response()->json([
            'data' => $query->orderByDesc('id')->limit(200)->get(),
            'meta' => [
                'task_create_idempotency' => 'external_id_v1',
                'filters' => ['external_id' => $data['external_id'] ?? null],
            ],
        ]);
    }

    public function store(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'external_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'priority' => ['nullable', 'string', 'in:low,normal,high'],
            'project_id' => ['nullable', 'string', 'max:190'],
            'conversation_id' => ['nullable', 'string', 'max:190'],
            'due_at' => ['nullable', 'date'],
            'client_id' => ['nullable', 'string', 'max:120'],
        ]);

        $externalId = (string) ($data['external_id'] ?? Str::uuid());
        $existing = Task::where('user_id', $request->user()->id)
            ->where('external_id', $externalId)
            ->first();
        if ($existing) {
            return response()->json([
                'data' => ['external_id' => $existing->external_id],
                'meta' => ['replayed' => true],
            ]);
        }

        $project = $actor->project($request, $data['project_id'] ?? null);
        if (! empty($data['project_id']) && ! $project) {
            abort(422, 'The selected project_id is invalid.');
        }

        $task = Task::firstOrCreate(
            ['user_id' => $request->user()->id, 'external_id' => $externalId],
            [
                'client_id' => $data['client_id'] ?? 'server',
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => 'open',
                'priority' => $data['priority'] ?? 'normal',
                'project_ref_id' => $project?->id,
                'conversation_id' => $data['conversation_id'] ?? null,
                'due_at' => $data['due_at'] ?? null,
            ]
        );

        return response()->json([
            'data' => ['external_id' => $task->external_id],
            'meta' => ['replayed' => ! $task->wasRecentlyCreated],
        ], $task->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Recover an ambiguous create for write-only device keys without exposing
     * the task body. The UUID and optional project remain account scoped.
     */
    public function verifyCreate(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'external_id' => ['required', 'uuid'],
            'project_id' => ['nullable', 'string', 'max:190'],
        ]);

        $query = Task::where('user_id', $request->user()->id)
            ->where('external_id', $data['external_id']);
        if (! empty($data['project_id'])) {
            $project = $actor->project($request, $data['project_id']);
            $project ? $query->where('project_ref_id', $project->id) : $query->whereRaw('1 = 0');
        }

        return response()->json([
            'data' => [
                'external_id' => $data['external_id'],
                'exists' => $query->exists(),
            ],
            'meta' => [
                'task_create_idempotency' => 'external_id_v1',
                'filters' => ['external_id' => $data['external_id']],
            ],
        ]);
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
            if ($data['project_id'] === null || $data['project_id'] === '') {
                $task->project_ref_id = null;
            } else {
                $project = $actor->project($request, $data['project_id']);
                if (! $project) {
                    abort(422, 'The selected project_id is invalid.');
                }
                $task->project_ref_id = $project->id;
            }
        }
        foreach (['title', 'description', 'status', 'priority', 'conversation_id'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $task->{$field} = $data[$field];
            }
        }
        if (($data['status'] ?? null) === 'done' && ! $task->completed_at) {
            $task->completed_at = now();
        }
        if (($data['status'] ?? null) && $data['status'] !== 'done') {
            $task->completed_at = null;
        }
        DB::transaction(function () use ($task) {
            $task->save();
            if ($task->wasChanged('status') && $task->status === 'done') {
                app(WorkflowEventService::class)->recordTaskTerminal($task);
            }
        });

        return response()->json(['data' => $task]);
    }
}
