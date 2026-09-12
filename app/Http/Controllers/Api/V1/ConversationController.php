<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\ApiActor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** SOLL §8/§1.8 — multiple chats per project. */
class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'string', 'max:190'],
            'external_id' => ['nullable', 'uuid'],
            'include_archived' => ['sometimes', 'boolean'],
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'between:1,200'],
        ]);

        $query = Conversation::where('user_id', $request->user()->id)
            ->when(! ($data['include_archived'] ?? false), fn ($query) => $query->whereNull('archived_at'));
        if (! empty($data['project_id'])) {
            $project = app(ApiActor::class)->project($request, $data['project_id']);
            $project ? $query->where('project_ref_id', $project->id) : $query->whereRaw('1 = 0');
        }
        if (! empty($data['external_id'])) {
            $query->where('external_id', $data['external_id']);
        }

        // Explicit cursor requests use a stable ID order; preserve the legacy recent-chat order otherwise.
        $paged = array_key_exists('after', $data);
        $rows = $paged ? $query->where('id', '>', $data['after'])->orderBy('id')->limit($data['limit'] ?? 200)->get()
            : $query->orderByDesc('last_message_at')->limit($data['limit'] ?? 200)->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'conversation_create_idempotency' => 'external_id_v1',
                'filters' => ['external_id' => $data['external_id'] ?? null],
                'next_cursor' => $paged ? ($rows->last()->id ?? $data['after']) : null,
                'has_more' => $paged && $rows->isNotEmpty() && (clone $query)->where('id', '>', $rows->last()->id)->exists(),
            ],
        ]);
    }

    public function store(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'external_id' => ['nullable', 'uuid'],
            'title' => ['nullable', 'string', 'max:200'],
            'project_id' => ['nullable', 'string', 'max:190'],
            'client_id' => ['nullable', 'string', 'max:120'],
        ]);

        $externalId = (string) ($data['external_id'] ?? Str::uuid());
        $existing = Conversation::where('user_id', $request->user()->id)
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

        $conversation = Conversation::firstOrCreate(
            ['user_id' => $request->user()->id, 'external_id' => $externalId],
            [
                'client_id' => $data['client_id'] ?? 'server',
                'project_ref_id' => $project?->id,
                'title' => $data['title'] ?? null,
                'last_message_at' => now(),
            ]
        );

        return response()->json([
            'data' => ['external_id' => $conversation->external_id],
            'meta' => ['replayed' => ! $conversation->wasRecentlyCreated],
        ], $conversation->wasRecentlyCreated ? 201 : 200);
    }

    /** Verify an ambiguous write without exposing conversation content. */
    public function verifyCreate(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'external_id' => ['required', 'uuid'],
            'project_id' => ['nullable', 'string', 'max:190'],
        ]);

        $query = Conversation::where('user_id', $request->user()->id)
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
                'conversation_create_idempotency' => 'external_id_v1',
                'filters' => ['external_id' => $data['external_id']],
            ],
        ]);
    }
}
