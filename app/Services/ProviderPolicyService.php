<?php

namespace App\Services;

use App\Models\ModelProfile;
use App\Models\ModelRanking;
use App\Models\ModelUseCase;

/** Enforces the server-owned allow-list, output budget and fallback ladder. */
class ProviderPolicyService
{
    /** @return array<int,ModelProfile> */
    public function candidates(?string $requestedModel, string $taskType): array
    {
        // Intentionally ignore client-supplied model identifiers. Customers
        // describe the task; only the admin-managed use-case ladder and the
        // measured ranking decide which model is used.
        $profiles = collect();

        $useCase = $this->useCaseFor($taskType);
        if ($useCase) {
            $fallbacks = $useCase->entries()
                ->where('active', true)
                ->orderBy('sort_order')
                ->with('modelProfile')
                ->get()
                ->pluck('modelProfile')
                ->filter(fn ($profile) => $profile?->active && $profile->provider === 'openrouter');
            $profiles = $fallbacks->values();
        }

        if ($profiles->isEmpty()) {
            $profiles = ModelProfile::query()
                ->where('slug', config('luczor.default_model_profile'))
                ->where('provider', 'openrouter')
                ->where('active', true)
                ->get();
        }

        abort_if($profiles->isEmpty(), 503, 'No enabled OpenRouter model profile is available.');

        $profiles = $profiles->unique('id')->values();
        $ranking = ModelRanking::query()
            ->whereNull('user_id')
            ->where('task_type', $taskType)
            ->whereIn('model_id', $profiles->pluck('model_id'))
            ->where('sample_count', '>=', 5)
            ->get()->keyBy('model_id');

        if ($ranking->isNotEmpty()) {
            $count = max(1, $profiles->count());
            $profiles = $profiles->values()->sortByDesc(function (ModelProfile $profile, int $index) use ($ranking, $count) {
                $measured = (float) ($ranking->get($profile->model_id)?->score ?? 0);
                $adminPriority = 1 - ($index / $count);
                return 0.65 * $measured + 0.35 * $adminPriority;
            })->values();
        }

        return $profiles->all();
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
            'stt' => 'stt',
            'tts' => 'tts',
            'verification', 'verifier' => 'verifier',
            default => 'chat',
        };

        return ModelUseCase::query()->where('slug', $slug)->where('active', true)->first();
    }
}
