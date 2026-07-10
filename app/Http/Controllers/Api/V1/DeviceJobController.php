<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceJob;
use App\Services\ApiActor;
use App\Services\DeviceJobService;
use Illuminate\Http\Request;

class DeviceJobController extends Controller
{
    public function store(Request $request, DeviceJobService $jobs)
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:120'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'agent_run_id' => ['nullable', 'integer', 'exists:agent_runs,id'],
            'tool_profile' => ['required', 'string', 'max:120'],
            'payload' => ['nullable', 'array'],
        ]);
        return response()->json(['data' => $jobs->create($request, $data)], 201);
    }

    public function index(Request $request, ApiActor $actor)
    {
        $query = DeviceJob::query()->with('device')->latest();
        if (! $request->user()?->isAdmin()) {
            $query->where('user_id', $actor->userId($request));
        }

        return response()->json(['data' => $query->paginate(50)]);
    }
}
