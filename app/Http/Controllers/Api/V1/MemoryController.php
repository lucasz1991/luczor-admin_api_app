<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LuczorMemoryService;
use App\Services\ApiActor;
use Illuminate\Http\Request;

/**
 * Server memory API. The desktop client calls these with its device key; the
 * server talks to the internal Cognee (never exposed publicly) and the
 * memory_links System-of-Record. Provider/engine endpoints stay internal.
 */
class MemoryController extends Controller
{
    private function ids(Request $request): array
    {
        return [
            'user_id' => $request->user()?->id,
            'project_id' => $request->input('project_id'),
            'agent_id' => $request->input('agent_id'),
        ];
    }

    public function remember(Request $request, LuczorMemoryService $memory, ApiActor $actor)
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:8000'],
            'scope' => ['nullable', 'string', 'in:private,project,skill,agent,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'feature_key' => ['nullable', 'string', 'max:160'],
            'session_id' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:60'],
            'visibility' => ['nullable', 'string', 'in:private,syncable,public'],
            'importance' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'external_id' => ['nullable', 'string', 'max:190'],
            'client_id' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
        ]);

        abort_if(($data['scope'] ?? 'project') === 'global' && ! $request->user()?->isAdmin(), 403, 'Only an administrator can publish global memory.');

        $project = $actor->project($request, $data['project_id'] ?? null);
        $link = $memory->remember(array_merge($data, [
            'user_id' => $actor->userId($request),
            'client_id' => $actor->deviceId($request, $data['client_id'] ?? null),
            'project_ref_id' => $project?->id,
            'scope' => $data['scope'] ?? 'project',
            'meta' => array_merge($data['meta'] ?? [], ['tags' => $data['tags'] ?? []]),
        ]));

        return response()->json([
            'ok' => true,
            'id' => $link->external_id,
            'cognee_id' => $link->cognee_memory_id,
        ], 201);
    }

    public function recall(Request $request, LuczorMemoryService $memory)
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:2000'],
            'scope' => ['nullable', 'string', 'in:private,project,skill,agent,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'agent_id' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        abort_if(($data['scope'] ?? 'project') === 'global' && ! $request->user()?->isAdmin(), 403, 'Global memory is administrator-managed.');

        $items = $memory->recall(
            $data['query'] ?? '',
            $data['scope'] ?? 'project',
            $this->ids($request),
            (int) ($data['limit'] ?? 6)
        );

        return response()->json(['data' => $items]);
    }

    public function forget(Request $request, LuczorMemoryService $memory, ApiActor $actor)
    {
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:190'],
            'scope' => ['nullable', 'string', 'in:private,project,skill,agent,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'client_id' => ['nullable', 'string', 'max:120'],
        ]);

        abort_if(($data['scope'] ?? 'project') === 'global' && ! $request->user()?->isAdmin(), 403, 'Global memory is administrator-managed.');

        $memory->forget(
            $data['scope'] ?? 'project',
            $data['external_id'],
            $this->ids($request),
            $actor->deviceId($request, $data['client_id'] ?? null)
        );

        return response()->json(['ok' => true]);
    }

    public function improve(Request $request, LuczorMemoryService $memory)
    {
        $data = $request->validate([
            'scope' => ['nullable', 'string', 'in:private,project,skill,agent,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
        ]);

        abort_if(($data['scope'] ?? 'project') === 'global' && ! $request->user()?->isAdmin(), 403, 'Global memory is administrator-managed.');

        $memory->improve($data['scope'] ?? 'project', $this->ids($request));

        return response()->json(['ok' => true]);
    }
}
