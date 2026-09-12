<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ApiActor;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function index(Request $request, ApiActor $actor)
    {
        $request->validate(['scope' => ['sometimes', 'in:cloud']]);
        $query = Project::query()->withCount('repositories')->latest('updated_at');
        if ($request->query('scope') === 'cloud') {
            $query->where('cloud_enabled', true);
        }
        if ($request->query('scope') === 'cloud' || ! $request->user()?->isAdmin()) {
            $query->where('user_id', $actor->userId($request));
        }

        return response()->json(['data' => $query->paginate(50)]);
    }

    public function store(Request $request, ApiActor $actor, AuditLogger $audit)
    {
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,archived'],
            'meta' => ['nullable', 'array'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $ownerId = $request->user()?->isAdmin() && ! empty($data['user_id'])
            ? (int) $data['user_id']
            : $actor->userId($request);
        $project = Project::firstOrCreate(
            ['user_id' => $ownerId, 'external_id' => $data['external_id']],
            ['name' => $data['name'], 'status' => $data['status'] ?? 'active', 'meta' => $data['meta'] ?? null]
        );
        $audit->record([
            'actor_user_id' => $actor->userId($request), 'project_id' => $project->id,
            'event_type' => 'project.saved', 'outcome' => 'completed',
            'payload' => ['external_id' => $project->external_id, 'owner_user_id' => $ownerId],
        ]);

        return response()->json([
            'data' => $project,
            'meta' => ['replayed' => ! $project->wasRecentlyCreated],
        ], $project->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, Project $project, ApiActor $actor)
    {
        $actor->assertOwned($request, $project);

        return response()->json(['data' => $project->load(['repositories'])]);
    }

    public function update(Request $request, Project $project, ApiActor $actor, AuditLogger $audit)
    {
        $actor->assertOwned($request, $project);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ]);

        return DB::transaction(function () use ($request, $project, $actor, $audit, $data) {
            $current = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $actor->assertOwned($request, $current);
            if ($current->cloud_enabled && (array_key_exists('name', $data) || array_key_exists('status', $data))) {
                return response()->json([
                    'code' => 'project_revision_required',
                    'message' => 'Use the cloud project endpoint with its current revision to update a shared project.',
                ], 409);
            }
            $current->update($data);
            $audit->record([
                'actor_user_id' => $actor->userId($request), 'project_id' => $current->id,
                'event_type' => 'project.updated', 'outcome' => 'completed', 'payload' => array_keys($data),
            ]);

            return response()->json(['data' => $current->fresh()]);
        }, 3);
    }
}
