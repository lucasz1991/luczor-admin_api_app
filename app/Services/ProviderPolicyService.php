<?php

namespace App\Services;

use App\Models\ModelProfile;
use App\Models\ModelRanking;
use App\Models\ModelUseCase;
use App\Models\ProviderPriceSnapshot;
use App\Models\LlmExperiment;

/** Enforces the server-owned allow-list, output budget and fallback ladder. */
class ProviderPolicyService
{
    private string $selectionSource = 'admin_policy';

    /**
     * @param array<int,string> $requiredCapabilities
     * @return array<int,ModelProfile>
     */
    public function candidates(?string $requestedModel, string $taskType, array $requiredCapabilities = []): array
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
                // P2b — every provider with a wire driver is routable
                // (openrouter/openai-compat, openai responses, anthropic messages).
                ->filter(fn ($profile) => $profile?->active && in_array($profile->provider, ['openrouter', 'openai', 'anthropic'], true));
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

        // SOLL §18 — capability routing: drop profiles that declare capabilities
        // but lack a required one (vision/tools). Profiles with unknown (empty)
        // capabilities are kept. If the filter empties the ladder, fall back to
        // the unfiltered set rather than dead-ending the request.
        if ($requiredCapabilities !== []) {
            $filtered = $profiles->filter(function (ModelProfile $profile) use ($requiredCapabilities) {
                $caps = is_array($profile->capabilities) ? $profile->capabilities : [];
                if ($caps === []) {
                    return true;
                }
                return count(array_intersect($requiredCapabilities, $caps)) === count($requiredCapabilities);
            })->values();
            if ($filtered->isNotEmpty()) {
                $profiles = $filtered;
                $this->selectionSource = 'admin_policy_capability';
            }
        }

        $ranking = ModelRanking::query()
            ->whereNull('user_id')
            ->where('task_type', $taskType)
            ->whereIn('model_id', $profiles->pluck('model_id'))
            ->where('sample_count', '>=', 5)
            ->get()->keyBy('model_id');

        if ($ranking->isNotEmpty()) {
            $this->selectionSource = 'admin_policy_ranked';
            $count = max(1, $profiles->count());
            $profiles = $profiles->values()->sortByDesc(function (ModelProfile $profile, int $index) use ($ranking, $count) {
                $measured = (float) ($ranking->get($profile->model_id)?->score ?? 0);
                $adminPriority = 1 - ($index / $count);
                return 0.65 * $measured + 0.35 * $adminPriority;
            })->values();
        }


        $experimentTask = explode('.', $taskType)[0] ?? $taskType;
        $experiment = LlmExperiment::query()->where('status', 'active')->whereIn('task_type', [$taskType, $experimentTask])
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->first();
        if ($experiment && random_int(1, 100) <= $experiment->traffic_percent) {
            $variants = collect($experiment->variants)->filter(fn ($item) => is_array($item));
            $weighted = $variants->flatMap(function (array $item) {
                $identity = $item['model_profile_slug'] ?? $item['model_id'] ?? null;
                return $identity ? array_fill(0, max(1, min(100, (int) ($item['weight'] ?? 1))), $identity) : [];
            })->values();
            $chosen = $weighted->isNotEmpty() ? $weighted->get(random_int(0, $weighted->count() - 1)) : null;
            $variant = $profiles->first(fn (ModelProfile $profile) => $profile->slug === $chosen || $profile->model_id === $chosen);
            if ($variant) {
                $profiles = collect([$variant])->concat($profiles->reject(fn (ModelProfile $p) => $p->id === $variant->id))->values();
                $this->selectionSource = 'experiment:'.$experiment->key;
            }
        }

        return $profiles->all();
    }

    public function selectionSource(): string { return $this->selectionSource; }

    public function outputBudget(ModelProfile $profile, mixed $requested): int
    {
        $requested = is_numeric($requested) ? (int) $requested : $profile->max_tokens;
        $hardLimit = (int) config('luczor.proxy.max_output_tokens', 8192);

        return max(1, min($requested, (int) $profile->max_tokens, $hardLimit));
    }

    /** @param array<string,mixed> $payload */
    public function estimatedInputTokens(array $payload): int
    {
        $characters = collect($payload['messages'] ?? [])->sum(fn ($message) => mb_strlen((string) ($message['content'] ?? '')));
        $characters += mb_strlen(json_encode($payload['tools'] ?? [], JSON_UNESCAPED_UNICODE));
        return max(1, (int) ceil($characters / 4));
    }

    /** @param array<string,mixed> $payload */
    public function estimatedCost(ModelProfile $profile, array $payload, int $outputTokens): ?float
    {
        $price = ProviderPriceSnapshot::current($profile->provider, $profile->model_id);
        if (! $price) return null;
        return round(($this->estimatedInputTokens($payload) / 1_000_000) * $price->input_per_million
            + ($outputTokens / 1_000_000) * $price->output_per_million, 8);
    }

    public function useCaseFor(string $taskType): ?ModelUseCase
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
