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
        ]);

        $query = Conversation::where('user_id', $request->user()->id)->whereNull('archived_at');
        if (! empty($data['project_id'])) {
            $project = app(ApiActor::class)->project($request, $data['project_id']);
            $project ? $query->where('project_ref_id', $project->id) : $query->whereRaw('1 = 0');
        }
        if (! empty($data['external_id'])) {
            $query->where('external_id', $data['external_id']);
        }

        return response()->json([
            'data' => $query->orderByDesc('last_message_at')->limit(200)->get(),
            'meta' => [
                'conversation_create_idempotency' => 'external_id_v1',
                'filters' => ['external_id' => $data['external_id'] ?? null],
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
