<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function bootstrap(Request $request)
    {
        $apiKey = $request->attributes->get('apiKey');

        return response()->json([
            'device' => [
                'id' => $apiKey?->device_id,
                'name' => $apiKey?->device_name,
                'abilities' => $apiKey?->abilities ?? [],
            ],
            'user' => [
                'id' => $request->user()?->id,
                'name' => $request->user()?->name,
                'email' => $request->user()?->email,
            ],
            'runtime_settings' => $this->runtimeSettingsPayload(),
            'model_profiles' => ModelProfile::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'provider', 'model_id', 'temperature', 'max_tokens', 'purpose', 'active']),
            'model_use_cases' => $this->modelUseCasesPayload(),
        ]);
    }

    public function modelProfiles()
    {
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
            'model_use_cases' => $this->modelUseCasesPayload(),
        ]);
    }

    private function runtimeSettingsPayload(): array
    {
        return [
            'default_model_profile' => config('luczor.default_model_profile'),
            'api_prefix' => config('luczor.api_prefix'),
            'registration_enabled' => (bool) config('luczor.allow_registration'),
        ];
    }

    private function modelUseCasesPayload()
    {
        return ModelUseCase::query()
            ->where('active', true)
            ->with(['entries' => function ($query) {
                $query->where('active', true)->orderBy('sort_order')->with('modelProfile');
            }])
            ->orderBy('slug')
            ->get()
            ->map(fn (ModelUseCase $useCase) => [
                'name' => $useCase->name,
                'slug' => $useCase->slug,
                'description' => $useCase->description,
                'fallbacks' => $useCase->entries
                    ->filter(fn ($entry) => $entry->modelProfile?->active)
                    ->map(fn ($entry) => [
                        'order' => $entry->sort_order,
                        'model_profile' => [
                            'slug' => $entry->modelProfile->slug,
                            'name' => $entry->modelProfile->name,
                            'provider' => $entry->modelProfile->provider,
                            'model_id' => $entry->modelProfile->model_id,
                            'temperature' => $entry->modelProfile->temperature,
                            'max_tokens' => $entry->modelProfile->max_tokens,
                        ],
                    ])
                    ->values(),
            ])
            ->values();
    }
}
