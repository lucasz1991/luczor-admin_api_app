<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Policy;
use App\Services\ApiActor;
use Illuminate\Http\Request;

class PolicyController extends Controller
{
    public function index(Request $request, ApiActor $actor)
    {
        $query = Policy::query()->orderBy('type')->orderBy('name');
        if (! $request->user()?->isAdmin()) {
            $query->where('user_id', $actor->userId($request));
        }
        return response()->json(['data' => $query->paginate(100)]);
    }

    public function store(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:prompt,context,network,tool,git'],
            'name' => ['required', 'string', 'max:120'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'risk_level' => ['nullable', 'string', 'in:low,normal,sensitive,critical'],
            'rules' => ['required', 'array'],
            'global' => ['nullable', 'boolean'],
        ]);
        $global = (bool) ($data['global'] ?? false);
        abort_if($global && ! $request->user()?->isAdmin(), 403, 'Only an administrator may define global policies.');
        $project = $actor->project($request, $data['project_id'] ?? null);
        $policy = Policy::updateOrCreate(
            ['user_id' => $global ? null : $actor->userId($request), 'project_id' => $project?->id, 'type' => $data['type'], 'name' => $data['name']],
            ['active' => true, 'risk_level' => $data['risk_level'] ?? 'normal', 'rules' => $data['rules']]
        );
        return response()->json(['data' => $policy], 201);
    }

    public function audit(Request $request, ApiActor $actor)
    {
        $query = AuditEvent::query()->latest();
        if (! $request->user()?->isAdmin()) {
            $query->where('actor_user_id', $actor->userId($request));
        }
        return response()->json(['data' => $query->paginate(100)]);
    }
}
