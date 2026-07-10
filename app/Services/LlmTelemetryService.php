<?php

namespace App\Services;

use App\Models\LlmAttempt;
use App\Models\LlmRun;
use App\Models\ModelProfile;
use App\Models\ProviderCredential;
use App\Models\ProviderPriceSnapshot;
use Illuminate\Support\Str;

class LlmTelemetryService
{
    public function __construct(private EvaluationService $evaluator)
    {
    }

    /** @param array<string,mixed> $meta @param array<string,mixed> $requestPayload */
    public function startRun(array $meta, string $taskType, array $requestPayload): LlmRun
    {
        return LlmRun::create([
            ...$meta,
            'request_id' => (string) Str::uuid(),
            'task_type' => $taskType,
            'model_id' => 'pending',
            'provider_id' => 'openrouter',
            'selected_by' => 'admin_policy',
            'status' => 'running',
            'success' => false,
            'request_hash' => $this->stableHash($this->redactRequest($requestPayload)),
        ]);
    }

    /** @param array<string,mixed> $routingMeta */
    public function startAttempt(
        LlmRun $run,
        ModelProfile $profile,
        ProviderCredential $credential,
        int $attemptNo,
        array $routingMeta = []
    ): LlmAttempt {
        $price = ProviderPriceSnapshot::current($profile->provider, $profile->model_id);

        return $run->attempts()->create([
            'model_profile_id' => $profile->id,
            'provider_credential_id' => $credential->id,
            'price_snapshot_id' => $price?->id,
            'attempt_no' => $attemptNo,
            'provider_id' => $profile->provider,
            'model_id' => $profile->model_id,
            'status' => 'started',
            'request_hash' => $run->request_hash,
            'routing_meta' => $routingMeta,
            'started_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $usage @param array<string,mixed> $extra */
    public function finishAttempt(
        LlmAttempt $attempt,
        int $httpStatus,
        int $totalMs,
        array $usage = [],
        array $extra = []
    ): LlmAttempt {
        $tokens = $this->usageTokens($usage);
        $providerCost = $this->nullableFloat($usage['cost'] ?? $usage['total_cost'] ?? null);
        $calculated = $this->calculateCost($attempt, $tokens);
        $effective = $providerCost ?? $calculated;
        $ttft = isset($extra['ttft_ms']) ? (int) $extra['ttft_ms'] : null;
        $outputTokens = $tokens['output_tokens'];
        $generationMs = max(1, $totalMs - (int) ($ttft ?? 0));
        $tps = $outputTokens !== null ? round($outputTokens / ($generationMs / 1000), 4) : null;
        $ok = $httpStatus >= 200 && $httpStatus < 300 && empty($extra['error_type']);

        $attempt->fill([
            'upstream_generation_id' => $extra['generation_id'] ?? null,
            'status' => $ok ? 'completed' : 'failed',
            'http_status' => $httpStatus,
            'finish_reason' => $extra['finish_reason'] ?? null,
            'error_type' => $extra['error_type'] ?? null,
            'error_message' => isset($extra['error_message']) ? mb_substr((string) $extra['error_message'], 0, 4000) : null,
            'connect_ms' => isset($extra['connect_ms']) ? (int) $extra['connect_ms'] : null,
            'ttft_ms' => $ttft,
            'total_ms' => $totalMs,
            ...$tokens,
            'tokens_per_second' => $tps,
            'provider_cost' => $providerCost,
            'calculated_cost' => $calculated,
            'effective_cost' => $effective,
            'cost_source' => $providerCost !== null ? 'provider' : ($calculated !== null ? 'price_snapshot' : 'unknown'),
            'response_hash' => $extra['response_hash'] ?? null,
            'raw_usage' => $usage ?: null,
            'first_token_at' => $ttft !== null && $attempt->started_at ? $attempt->started_at->copy()->addMilliseconds($ttft) : null,
            'finished_at' => now(),
        ])->save();

        return $attempt->refresh();
    }

    public function failAttempt(LlmAttempt $attempt, string $type, string $message, int $totalMs, ?int $status = null): LlmAttempt
    {
        return $this->finishAttempt($attempt, $status ?? 0, $totalMs, [], [
            'error_type' => $type,
            'error_message' => $message,
        ]);
    }

    public function finishRun(LlmRun $run, LlmAttempt $attempt): LlmRun
    {
        $ok = $attempt->status === 'completed';
        $run->fill([
            'model_id' => $attempt->model_id,
            'provider_id' => $attempt->provider_id,
            'status' => $ok ? 'ok' : 'error',
            'finish_reason' => $attempt->finish_reason,
            'success' => $ok,
            'attempt_count' => $run->attempts()->count(),
            'retry_count' => max(0, $run->attempts()->count() - 1),
            'latency_ms' => $attempt->total_ms,
            'ttft_ms' => $attempt->ttft_ms,
            'tokens_per_second' => $attempt->tokens_per_second,
            'input_tokens' => $attempt->input_tokens,
            'output_tokens' => $attempt->output_tokens,
            'cost_total' => $run->attempts()->sum('effective_cost'),
            'provider_cost' => $run->attempts()->sum('provider_cost'),
            'calculated_cost' => $run->attempts()->sum('calculated_cost'),
            'cost_source' => $attempt->cost_source,
            'response_hash' => $attempt->response_hash,
        ])->save();

        $this->evaluator->recordMetric($run, [
            'input_tokens' => $attempt->input_tokens,
            'output_tokens' => $attempt->output_tokens,
            'reasoning_tokens' => $attempt->reasoning_tokens,
            'cache_read_tokens' => $attempt->cache_read_tokens,
            'cache_write_tokens' => $attempt->cache_write_tokens,
            'latency_ms' => $attempt->total_ms,
            'ttft_ms' => $attempt->ttft_ms,
            'tokens_per_second' => $attempt->tokens_per_second,
            'retry_count' => $run->retry_count,
            'tool_call_count' => $run->tool_call_count,
            'cost_total' => $run->cost_total,
            'provider_cost' => $run->provider_cost,
            'calculated_cost' => $run->calculated_cost,
            'cost_source' => $run->cost_source,
            'prompt_template_id' => $run->prompt_template_id,
            'context_strategy_id' => $run->context_strategy_id,
            'network_policy_id' => $run->network_policy_id,
            'raw_usage' => $attempt->raw_usage,
            'provider_meta' => [
                'generation_id' => $attempt->upstream_generation_id,
                'finish_reason' => $attempt->finish_reason,
                'attempt_count' => $run->attempt_count,
                'model_profile_id' => $attempt->model_profile_id,
                'price_snapshot_id' => $attempt->price_snapshot_id,
            ],
        ]);

        return $run->refresh();
    }

    /** @return array<string,int|null> */
    private function usageTokens(array $usage): array
    {
        $details = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];
        $completion = is_array($usage['completion_tokens_details'] ?? null) ? $usage['completion_tokens_details'] : [];

        return [
            'input_tokens' => $this->nullableInt($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null),
            'output_tokens' => $this->nullableInt($usage['completion_tokens'] ?? $usage['output_tokens'] ?? null),
            'reasoning_tokens' => $this->nullableInt($completion['reasoning_tokens'] ?? null),
            'cache_read_tokens' => $this->nullableInt($details['cached_tokens'] ?? $usage['cache_read_tokens'] ?? null),
            'cache_write_tokens' => $this->nullableInt($usage['cache_write_tokens'] ?? null),
        ];
    }

    /** @param array<string,int|null> $tokens */
    private function calculateCost(LlmAttempt $attempt, array $tokens): ?float
    {
        $price = $attempt->price_snapshot_id ? ProviderPriceSnapshot::find($attempt->price_snapshot_id) : null;
        if (! $price) return null;

        $cost = (($tokens['input_tokens'] ?? 0) / 1_000_000) * $price->input_per_million
            + (($tokens['output_tokens'] ?? 0) / 1_000_000) * $price->output_per_million
            + (($tokens['cache_read_tokens'] ?? 0) / 1_000_000) * $price->cache_read_per_million
            + (($tokens['cache_write_tokens'] ?? 0) / 1_000_000) * $price->cache_write_per_million;

        return round($cost, 8);
    }

    private function redactRequest(array $payload): array
    {
        return [
            'model' => $payload['model'] ?? null,
            'message_roles' => collect($payload['messages'] ?? [])->pluck('role')->all(),
            'message_lengths' => collect($payload['messages'] ?? [])->map(fn ($m) => mb_strlen((string) ($m['content'] ?? '')))->all(),
            'tool_names' => collect($payload['tools'] ?? [])->pluck('function.name')->all(),
            'temperature' => $payload['temperature'] ?? null,
            'max_tokens' => $payload['max_tokens'] ?? null,
            'stream' => $payload['stream'] ?? false,
        ];
    }

    private function stableHash(mixed $value): string { return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
    private function nullableInt(mixed $v): ?int { return $v === null || $v === '' ? null : (int) $v; }
    private function nullableFloat(mixed $v): ?float { return $v === null || $v === '' ? null : (float) $v; }
}
