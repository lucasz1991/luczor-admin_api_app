<?php

namespace App\Services;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;

/** Enforces the server-owned allow-list, output budget and fallback ladder. */
class ProviderPolicyService
{
    /** @return array<int,ModelProfile> */
    public function candidates(?string $requestedModel, string $taskType): array
    {
        $profiles = collect();
        $requestedModel = trim((string) $requestedModel);

        if ($requestedModel !== '') {
            $profiles = ModelProfile::query()
                ->where('active', true)
                ->where('provider', 'openrouter')
                ->where(fn ($query) => $query->where('model_id', $requestedModel)->orWhere('slug', $requestedModel))
                ->get();
            abort_if($profiles->isEmpty(), 422, 'The requested model is not enabled by the server policy.');
        }

        $useCase = $this->useCaseFor($taskType);
        if ($useCase) {
            $fallbacks = $useCase->entries()
                ->where('active', true)
                ->orderBy('sort_order')
                ->with('modelProfile')
                ->get()
                ->pluck('modelProfile')
                ->filter(fn ($profile) => $profile?->active && $profile->provider === 'openrouter');
            $profiles = $profiles->concat($fallbacks);
        }

        if ($profiles->isEmpty()) {
            $profiles = ModelProfile::query()
                ->where('slug', config('luczor.default_model_profile'))
                ->where('provider', 'openrouter')
                ->where('active', true)
                ->get();
        }

        abort_if($profiles->isEmpty(), 503, 'No enabled OpenRouter model profile is available.');

        return $profiles->unique('id')->values()->all();
    }

    public function outputBudget(ModelProfile $profile, mixed $requested): int
    {
        $requested = is_numeric($requested) ? (int) $requested : $profile->max_tokens;
        $hardLimit = (int) config('luczor.proxy.max_output_tokens', 8192);

        return max(1, min($requested, (int) $profile->max_tokens, $hardLimit));
    }

    private function useCaseFor(string $taskType): ?ModelUseCase
    {
        $prefix = explode('.', $taskType)[0] ?? 'chat';
        $slug = match ($prefix) {
            'coding' => 'coding',
            'planning' => 'planner',
            'vision' => 'vision',
            default => 'chat',
        };

        return ModelUseCase::query()->where('slug', $slug)->where('active', true)->first();
    }
}
