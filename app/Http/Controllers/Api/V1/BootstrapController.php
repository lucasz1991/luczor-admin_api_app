<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ModelProfile;
use App\Models\Setting;
use App\Services\LocalModelManifestService;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function bootstrap(Request $request, LocalModelManifestService $localModels)
    {
        $apiKey = $request->attributes->get('apiKey');

        return response()->json([
            'device' => [
                'id' => $apiKey?->device_id,
                'name' => $apiKey?->device_name,
                'abilities' => $apiKey ? $apiKey->abilities : [],
            ],
            'user' => [
                'id' => $request->user()?->id,
                'name' => $request->user()?->name,
                'email' => $request->user()?->email,
            ],
            'runtime_settings' => $this->runtimeSettingsPayload(),
            'realtime' => $this->realtimePayload(),
            'local_model_manifest' => $localModels->discovery(),
            'routing' => $this->routingPayload(),
        ]);
    }

    public function modelProfiles(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return response()->json([
            'data' => ModelProfile::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'provider', 'model_id', 'temperature', 'max_tokens', 'purpose', 'active']),
        ]);
    }

    public function runtimeSettings()
    {
        return response()->json([
            'data' => $this->runtimeSettingsPayload(),
            'routing' => $this->routingPayload(),
        ]);
    }

    public function realtime()
    {
        return response()->json([
            'data' => $this->realtimePayload(),
        ]);
    }

    private function runtimeSettingsPayload(): array
    {
        return [
            'api_prefix' => config('luczor.api_prefix'),
            'registration_enabled' => (bool) config('luczor.allow_registration'),
            // Server-managed client defaults, editable in the admin dashboard.
            'settings' => Setting::asMap(),
        ];
    }

    /** @return array<string,mixed> */
    private function routingPayload(): array
    {
        return [
            // Legacy fields describe external provider selection only.
            'managed_by' => 'server',
            'client_model_selection' => false,
            'legacy_scope' => 'external_provider',
            'external_routing_managed_by' => 'server',
            'external_client_model_selection' => false,
            'local_routing_managed_by' => 'desktop_signed_policy',
            'local_model_manifest_required' => true,
        ];
    }

    /** @return array{key: ?string, host: ?string, port: int, scheme: ?string} */
    private function realtimePayload(): array
    {
        return [
            'key' => config('broadcasting.connections.reverb.key')
                ?: config('broadcasting.connections.pusher.key'),
            'host' => config('luczor.realtime.public_host'),
            'port' => (int) config('luczor.realtime.public_port'),
            'scheme' => config('luczor.realtime.public_scheme'),
        ];
    }
}
