<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;

class ApiKeyController extends Controller
{
    public function storeApiKey(Request $request)
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
        ];
        if ($request->user()?->isAdmin()) {
            $rules['abilities'] = ['required', 'array', 'min:1'];
            $rules['abilities.*'] = [Rule::in(ApiKey::ABILITIES)];
        }
        $data = $request->validate($rules);
        $abilities = $request->user()?->isAdmin()
            ? $data['abilities']
            : ['sync.read', 'sync.write', 'settings.read', 'brain.read', 'brain.write', 'proxy.use', 'device.connect', 'device.jobs.read', 'device.jobs.write'];

        $minted = ApiKey::mint([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'device_id' => $data['device_id'] ?? null,
            'device_name' => $data['device_name'] ?? null,
            'abilities' => $abilities,
            'expires_at' => $data['expires_at'] ?? null,
            'active' => true,
        ]);

        return Redirect::route('dashboard')->with('plain_api_key', $minted['plain']);
    }

    public function toggleApiKey(Request $request, ApiKey $apiKey)
    {
        if (! $request->user()?->isAdmin() && (int) $apiKey->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        $apiKey->forceFill(['active' => ! $apiKey->active])->save();

        return Redirect::route('dashboard')->with('status', 'API key updated.');
    }
}
