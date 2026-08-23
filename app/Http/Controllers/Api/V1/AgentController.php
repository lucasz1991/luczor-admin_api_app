<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentProfile;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index()
    {
        return response()->json(['data' => AgentProfile::query()->where('status', 'active')->get(['key', 'name', 'type', 'capabilities', 'required_sources'])]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120'], 'name' => ['required', 'string', 'max:190'],
            'type' => ['required', 'string', 'max:80'], 'prompt_template_key' => ['nullable', 'string', 'max:120'],
            'capabilities' => ['nullable', 'array'], 'required_sources' => ['nullable', 'array'], 'config' => ['nullable', 'array'],
        ]);
        $profile = AgentProfile::updateOrCreate(['key' => $data['key']], $data + ['status' => 'active']);

        return response()->json(['data' => $profile], 201);
    }
}
