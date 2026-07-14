<?php

namespace App\Services;

use App\Models\NetworkPolicy;

class NetworkOptimizer
{
    public function policy(?string $key = null): NetworkPolicy
    {
        return NetworkPolicy::query()
            ->where('status', 'active')
            ->when($key, fn ($q) => $q->where('key', $key))
            ->first()
            ?? new NetworkPolicy([
                'key' => 'proxy.openrouter.default',
                'name' => 'OpenRouter Default',
                'connect_timeout_ms' => config('luczor.proxy.connect_timeout') * 1000,
                'request_timeout_ms' => config('luczor.proxy.timeout') * 1000,
                'max_attempts' => 3,
                'backoff_ms' => 250,
                'max_input_tokens' => config('luczor.proxy.max_input_tokens', 24000),
                'max_output_tokens' => config('luczor.proxy.max_output_tokens'),
            ]);
    }

    public function shouldRetry(int $status, int $attempt, int $availableCandidates, NetworkPolicy $policy): bool
    {
        // 529 is Anthropic's "overloaded" status — retriable like 503.
        return $attempt < min($availableCandidates, (int) $policy->max_attempts)
            && in_array($status, [0, 408, 409, 425, 429, 500, 502, 503, 504, 529], true);
    }

    public function backoff(NetworkPolicy $policy, int $attempt): void
    {
        $base = max(0, (int) $policy->backoff_ms);
        if ($base > 0) usleep(($base * (2 ** max(0, $attempt - 1)) + random_int(0, 100)) * 1000);
    }
}
