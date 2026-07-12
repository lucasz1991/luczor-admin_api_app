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
        ]);

        $query = Conversation::where('user_id', $request->user()->id)->whereNull('archived_at');
        if (! empty($data['project_id'])) {
            $project = app(ApiActor::class)->project($request, $data['project_id']);
            $query->where('project_ref_id', $project?->id);
        }

        return response()->json(['data' => $query->orderByDesc('last_message_at')->limit(200)->get()]);
    }

    public function store(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'project_id' => ['nullable', 'string', 'max:190'],
            'client_id' => ['nullable', 'string', 'max:120'],
        ]);

        $project = $actor->project($request, $data['project_id'] ?? null);

        $conversation = Conversation::create([
            'user_id' => $request->user()->id,
            'client_id' => $data['client_id'] ?? 'server',
            'external_id' => (string) Str::uuid(),
            'project_ref_id' => $project?->id,
            'title' => $data['title'] ?? null,
            'last_message_at' => now(),
        ]);

        return response()->json(['data' => $conversation], 201);
    }
}
