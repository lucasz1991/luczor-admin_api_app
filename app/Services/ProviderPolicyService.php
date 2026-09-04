<?php

namespace App\Services;

use App\Data\ProviderRoutingDecision;
use App\Exceptions\RoutingPolicyException;
use App\Models\LlmExperiment;
use App\Models\ModelProfile;
use App\Models\ModelRanking;
use App\Models\ModelUseCase;
use App\Models\NetworkPolicy;
use App\Models\ProviderPriceSnapshot;
use App\Services\Llm\ProviderWireFormat;
use Illuminate\Support\Collection;
use JsonException;

/** Enforces the server-owned external provider allow-list and fallback ladder. */
class ProviderPolicyService
{
    private const CHAT_BASE_OVERHEAD_TOKENS = 3;

    private const CHAT_MESSAGE_OVERHEAD_TOKENS = 4;

    private const CHAT_TOOL_OVERHEAD_TOKENS = 8;

    private string $selectionSource = 'admin_policy_manual';

    public function __construct(private ?NetworkOptimizer $networkOptimizer = null) {}

    /**
     * Resolve one complete, fail-closed decision before any provider request is
     * attempted. Local model selection is deliberately outside this service.
     *
     * @param  array<int,string>  $requiredCapabilities
     * @param  array<string,mixed>  $payload
     */
    public function resolve(string $taskType, array $requiredCapabilities, array $payload): ProviderRoutingDecision
    {
        $this->selectionSource = 'admin_policy_manual';
        $useCase = $this->useCaseFor($taskType);
        if (! $useCase) {
            throw new RoutingPolicyException('routing_use_case_unavailable');
        }

        $networkKey = is_string($useCase->network_policy_key) ? trim($useCase->network_policy_key) : '';
        if ($networkKey === '') {
            throw new RoutingPolicyException('routing_network_policy_unavailable');
        }
        $networkPolicy = $this->optimizer()->policy($networkKey);
        $this->optimizer()->retryStatuses($networkPolicy);
        $this->assertBudgetContract($useCase, $networkPolicy);

        $profiles = $this->baseProfiles($useCase, $requiredCapabilities);
        $maxCostUsd = $this->tightestFloat($networkPolicy->max_cost_usd, $useCase->max_cost_usd);
        $estimatedCosts = [];
        $excluded = [];
        $eligible = collect();

        foreach ($profiles as $profile) {
            $profile->loadMissing('credential');
            $credential = $profile->credential;
            if (! $credential || ! ProviderWireFormat::isCompatible($profile, $credential)) {
                $excluded[] = 'routing_credential_incompatible';

                continue;
            }

            $outputTokens = $this->outputBudget(
                $profile,
                $payload['max_tokens'] ?? $networkPolicy->max_output_tokens,
            );
            $estimated = $this->estimatedCost($profile, $payload, $outputTokens);
            $estimatedCosts[$profile->id] = $estimated;
            if ($estimated === null) {
                $excluded[] = 'routing_price_unavailable';

                continue;
            }
            if ($maxCostUsd !== null && $estimated > $maxCostUsd) {
                $excluded[] = 'routing_budget_exceeded';

                continue;
            }

            $eligible->push($profile);
        }

        if ($eligible->isEmpty()) {
            throw new RoutingPolicyException($this->terminalReason($excluded), $this->terminalStatus($excluded));
        }

        [$eligible, $reasonCode] = $this->applyStrategy($useCase, $eligible->values(), $taskType);
        $maxAttempts = min(
            max(1, (int) $useCase->max_attempts),
            max(1, (int) $networkPolicy->max_attempts),
            $eligible->count(),
        );
        $reservedCostUsd = $this->reservationFromKnownCosts(
            $eligible->all(),
            $estimatedCosts,
            $maxAttempts,
        );
        if ($maxCostUsd !== null && $reservedCostUsd > $maxCostUsd) {
            throw new RoutingPolicyException('routing_budget_exceeded', 422);
        }

        return new ProviderRoutingDecision(
            useCase: $useCase,
            networkPolicy: $networkPolicy,
            profiles: $eligible->all(),
            selectionSource: $this->selectionSource,
            reasonCode: $reasonCode,
            policyVersion: max(1, (int) $useCase->policy_version),
            maxAttempts: $maxAttempts,
            maxInputTokens: $this->tightestInt($networkPolicy->max_input_tokens, $useCase->max_input_tokens),
            maxOutputTokens: $this->nullablePositiveInt($networkPolicy->max_output_tokens),
            maxCostUsd: $maxCostUsd,
            reservedCostUsd: $reservedCostUsd,
            estimatedCostsByProfileId: $estimatedCosts,
            excludedReasonCodes: array_values(array_unique($excluded)),
        );
    }

    /**
     * Compatibility surface for the read-only route preview and existing admin
     * tests. It remains strict about use-case membership and capabilities.
     *
     * @param  array<int,string>  $requiredCapabilities
     * @return array<int,ModelProfile>
     */
    public function candidates(?string $requestedModel, string $taskType, array $requiredCapabilities = []): array
    {
        $this->selectionSource = 'admin_policy_manual';
        $useCase = $this->useCaseFor($taskType);
        if (! $useCase) {
            throw new RoutingPolicyException('routing_use_case_unavailable');
        }

        [$profiles] = $this->applyStrategy($useCase, $this->baseProfiles($useCase, $requiredCapabilities), $taskType);

        return $profiles->all();
    }

    public function selectionSource(): string
    {
        return $this->selectionSource;
    }

    public function outputBudget(ModelProfile $profile, mixed $requested): int
    {
        $requested = is_numeric($requested) ? (int) $requested : $profile->max_tokens;
        $hardLimit = (int) config('luczor.proxy.max_output_tokens', 8192);

        return max(1, min($requested, (int) $profile->max_tokens, $hardLimit));
    }

    /** @param array<string,mixed> $payload */
    public function estimatedInputTokens(array $payload): int
    {
        try {
            $wire = json_encode($this->canonicalizeWireValue([
                'messages' => $payload['messages'] ?? [],
                'tools' => $payload['tools'] ?? [],
            ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RoutingPolicyException('routing_input_unencodable', 422);
        }

        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        $tools = is_array($payload['tools'] ?? null) ? $payload['tools'] : [];

        // One token per UTF-8 byte is deliberately conservative across model
        // tokenizers. Explicit chat/tool framing overhead prevents empty or
        // structurally dense messages from being treated as free input.
        return max(
            1,
            strlen($wire)
                + self::CHAT_BASE_OVERHEAD_TOKENS
                + (count($messages) * self::CHAT_MESSAGE_OVERHEAD_TOKENS)
                + (count($tools) * self::CHAT_TOOL_OVERHEAD_TOKENS),
        );
    }

    /** @param array<string,mixed> $payload */
    public function estimatedCost(ModelProfile $profile, array $payload, int $outputTokens): ?float
    {
        $price = ProviderPriceSnapshot::current($profile->provider, $profile->model_id);
        if (! $price
            || strtoupper((string) $price->currency) !== 'USD'
            || ! is_finite((float) $price->input_per_million)
            || ! is_finite((float) $price->output_per_million)
            || (float) $price->input_per_million < 0
            || (float) $price->output_per_million < 0) {
            return null;
        }

        return $this->ceilUsd(
            ($this->estimatedInputTokens($payload) / 1_000_000) * $price->input_per_million
                + ($outputTokens / 1_000_000) * $price->output_per_million,
        );
    }

    /**
     * Re-price the remaining retry ladder immediately before dispatch. A null
     * result means at least one possible attempt lacks a current USD price.
     *
     * @param  array<int,ModelProfile>  $profiles
     * @param  array<string,mixed>  $payload
     * @return array{total:float,by_profile_id:array<int,float>}|null
     */
    public function currentCostReservation(
        array $profiles,
        array $payload,
        NetworkPolicy $networkPolicy,
        int $maxAttempts,
    ): ?array {
        $costs = [];
        $total = 0.0;
        foreach (array_slice($profiles, 0, max(0, $maxAttempts)) as $profile) {
            $outputTokens = $this->outputBudget(
                $profile,
                $payload['max_tokens'] ?? $networkPolicy->max_output_tokens,
            );
            $estimated = $this->estimatedCost($profile, $payload, $outputTokens);
            if ($estimated === null) {
                return null;
            }
            $costs[$profile->id] = $estimated;
            $total += $estimated;
        }

        return [
            'total' => $this->ceilUsd($total),
            'by_profile_id' => $costs,
        ];
    }

    public function useCaseFor(string $taskType): ?ModelUseCase
    {
        $prefix = explode('.', $taskType)[0];
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

    /**
     * @param  array<int,string>  $requiredCapabilities
     * @return Collection<int,ModelProfile>
     */
    private function baseProfiles(ModelUseCase $useCase, array $requiredCapabilities): Collection
    {
        $profiles = $useCase->entries()
            ->where('active', true)
            ->orderBy('sort_order')
            ->with('modelProfile.credential')
            ->get()
            ->pluck('modelProfile')
            ->filter(fn ($profile) => $profile?->active && ProviderWireFormat::allowedFor((string) $profile->provider) !== [])
            ->unique('id')
            ->values();

        if ($profiles->isEmpty()) {
            throw new RoutingPolicyException('routing_no_candidates');
        }

        $requiredCapabilities = array_values(array_unique(array_filter(
            $requiredCapabilities,
            static fn (string $capability): bool => $capability !== '',
        )));
        if ($requiredCapabilities !== []) {
            $profiles = $profiles->filter(function (ModelProfile $profile) use ($requiredCapabilities): bool {
                $capabilities = is_array($profile->capabilities) ? $profile->capabilities : [];

                return count(array_intersect($requiredCapabilities, $capabilities)) === count($requiredCapabilities);
            })->values();
            if ($profiles->isEmpty()) {
                throw new RoutingPolicyException('routing_capability_unavailable', 422);
            }
        }

        return $profiles;
    }

    /**
     * @param  Collection<int,ModelProfile>  $profiles
     * @return array{0:Collection<int,ModelProfile>,1:string}
     */
    private function applyStrategy(ModelUseCase $useCase, Collection $profiles, string $taskType): array
    {
        $strategy = (string) $useCase->routing_strategy;
        if ($strategy === 'manual') {
            $this->selectionSource = 'admin_policy_manual';

            return [$profiles->values(), 'external_policy_manual'];
        }

        if ($strategy === 'ranked') {
            $ranking = ModelRanking::query()
                ->whereNull('user_id')
                ->where('task_type', $taskType)
                ->whereIn('model_id', $profiles->pluck('model_id'))
                ->where('sample_count', '>=', 5)
                ->get()
                ->keyBy('model_id');
            if ($ranking->isEmpty()) {
                $this->selectionSource = 'admin_policy_ranked_insufficient_samples';

                return [$profiles->values(), 'external_policy_ranked_insufficient_samples'];
            }

            $adminOrder = $profiles->values()->pluck('id')->flip();
            $profiles = $profiles->sortByDesc(function (ModelProfile $profile) use ($ranking, $adminOrder): float {
                $measured = $ranking->get($profile->model_id);
                if (! $measured) {
                    return -1000000.0 - (float) ($adminOrder[$profile->id] ?? 0);
                }

                return (float) $measured->score - ((float) ($adminOrder[$profile->id] ?? 0) / 1000000);
            })->values();
            $this->selectionSource = 'admin_policy_ranked';

            return [$profiles, 'external_policy_ranked'];
        }

        if ($strategy !== 'experiment') {
            throw new RoutingPolicyException('routing_strategy_invalid');
        }

        $experimentTask = explode('.', $taskType)[0];
        $experiment = LlmExperiment::query()
            ->where('status', 'active')
            ->whereIn('task_type', [$taskType, $experimentTask])
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->first();
        if (! $experiment) {
            throw new RoutingPolicyException('routing_experiment_unavailable');
        }
        if ((int) $experiment->traffic_percent < 1 || random_int(1, 100) > (int) $experiment->traffic_percent) {
            $this->selectionSource = 'experiment_control:'.$experiment->key;

            return [$profiles->values(), 'external_policy_experiment_control'];
        }

        $weighted = collect($experiment->variants)
            ->filter(fn ($item): bool => is_array($item))
            ->flatMap(function (array $item) use ($profiles): array {
                $identity = $item['model_profile_slug'] ?? $item['model_id'] ?? null;
                $eligible = is_string($identity) && $profiles->contains(
                    fn (ModelProfile $profile): bool => $profile->slug === $identity || $profile->model_id === $identity,
                );

                return $eligible
                    ? array_fill(0, max(1, min(100, (int) ($item['weight'] ?? 1))), $identity)
                    : [];
            })->values();
        if ($weighted->isEmpty()) {
            throw new RoutingPolicyException('routing_experiment_invalid');
        }

        $chosen = $weighted->get(random_int(0, $weighted->count() - 1));
        $variant = $profiles->first(
            fn (ModelProfile $profile): bool => $profile->slug === $chosen || $profile->model_id === $chosen,
        );
        if (! $variant) {
            throw new RoutingPolicyException('routing_experiment_invalid');
        }

        $this->selectionSource = 'experiment:'.$experiment->key;

        return [
            collect([$variant])->concat($profiles->reject(fn (ModelProfile $profile): bool => $profile->id === $variant->id))->values(),
            'external_policy_experiment',
        ];
    }

    /** @param array<int,string> $excluded */
    private function terminalReason(array $excluded): string
    {
        foreach (['routing_price_unavailable', 'routing_budget_exceeded', 'routing_credential_incompatible'] as $reason) {
            if (in_array($reason, $excluded, true)) {
                return $reason;
            }
        }

        return 'routing_no_candidates';
    }

    /** @param array<int,string> $excluded */
    private function terminalStatus(array $excluded): int
    {
        return in_array('routing_budget_exceeded', $excluded, true) ? 422 : 503;
    }

    private function optimizer(): NetworkOptimizer
    {
        return $this->networkOptimizer ?? app(NetworkOptimizer::class);
    }

    private function assertBudgetContract(ModelUseCase $useCase, NetworkPolicy $networkPolicy): void
    {
        if ((int) $useCase->max_attempts < 1
            || ! $this->validOptionalPositiveInt($useCase->max_input_tokens)
            || ! $this->validOptionalPositiveInt($networkPolicy->max_input_tokens)
            || ! $this->validOptionalPositiveInt($networkPolicy->max_output_tokens)
            || ! $this->validOptionalNonNegativeFloat($useCase->max_cost_usd)
            || ! $this->validOptionalNonNegativeFloat($networkPolicy->max_cost_usd)) {
            throw new RoutingPolicyException('routing_budget_policy_invalid');
        }
    }

    private function validOptionalPositiveInt(mixed $value): bool
    {
        return $value === null || $value === '' || (is_numeric($value) && (int) $value > 0);
    }

    private function validOptionalNonNegativeFloat(mixed $value): bool
    {
        return $value === null || $value === '' || (
            is_numeric($value)
            && is_finite((float) $value)
            && (float) $value >= 0
        );
    }

    private function tightestInt(mixed $first, mixed $second): ?int
    {
        $first = $this->nullablePositiveInt($first);
        $second = $this->nullablePositiveInt($second);

        return $first !== null && $second !== null ? min($first, $second) : ($first ?? $second);
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function tightestFloat(mixed $first, mixed $second): ?float
    {
        $first = is_numeric($first) && (float) $first >= 0 ? (float) $first : null;
        $second = is_numeric($second) && (float) $second >= 0 ? (float) $second : null;

        return $first !== null && $second !== null ? min($first, $second) : ($first ?? $second);
    }

    /**
     * @param  array<int,ModelProfile>  $profiles
     * @param  array<int,float|null>  $estimatedCosts
     */
    private function reservationFromKnownCosts(array $profiles, array $estimatedCosts, int $maxAttempts): float
    {
        $total = 0.0;
        foreach (array_slice($profiles, 0, max(0, $maxAttempts)) as $profile) {
            $estimated = $estimatedCosts[$profile->id] ?? null;
            if ($estimated === null) {
                throw new RoutingPolicyException('routing_price_unavailable');
            }
            $total += $estimated;
        }

        return $this->ceilUsd($total);
    }

    private function ceilUsd(float $value): float
    {
        return ceil(($value * 100_000_000) - PHP_FLOAT_EPSILON) / 100_000_000;
    }

    private function canonicalizeWireValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalizeWireValue($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalizeWireValue($item), $value);
    }
}
