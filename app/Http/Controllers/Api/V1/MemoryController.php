<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LuczorMemoryService;
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

    public function remember(Request $request, LuczorMemoryService $memory)
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
        ]);

        $link = $memory->remember(array_merge($data, [
            'user_id' => $request->user()?->id,
            'scope' => $data['scope'] ?? 'project',
            'meta' => ['tags' => $data['tags'] ?? []],
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

        $items = $memory->recall(
            $data['query'] ?? '',
            $data['scope'] ?? 'project',
            $this->ids($request),
            (int) ($data['limit'] ?? 6)
        );

        return response()->json(['data' => $items]);
    }

    public function forget(Request $request, LuczorMemoryService $memory)
    {
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:190'],
            'scope' => ['nullable', 'string', 'in:private,project,skill,agent,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'client_id' => ['nullable', 'string', 'max:120'],
        ]);

        $memory->forget(
            $data['scope'] ?? 'project',
            $data['external_id'],
            $this->ids($request),
            $data['client_id'] ?? null
        );

        return response()->json(['ok' => true]);
    }

    public function improve(Request $request, LuczorMemoryService $memory)
    {
        $data = $request->validate([
            'scope' => ['nullable', 'string', 'in:private,project,skill,agent,global'],
            'project_id' => ['nullable', 'string', 'max:120'],
        ]);

        $memory->improve($data['scope'] ?? 'project', $this->ids($request));

        return response()->json(['ok' => true]);
    }
}
