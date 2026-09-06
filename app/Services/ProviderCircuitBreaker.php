<?php

namespace App\Services;

use App\Models\ModelProfile;
use App\Models\ProviderCredential;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;

final class ProviderCircuitBreaker
{
    /** Reserve at most one half-open probe after a provider circuit cools down. */
    public function allows(ModelProfile $profile, ProviderCredential $credential): bool
    {
        if (! (bool) config('luczor.proxy.circuit_breaker.enabled', true)) {
            return true;
        }

        return (bool) Cache::lock($this->key($profile, $credential).':lock', 5)->block(2, function () use ($profile, $credential): bool {
            $key = $this->key($profile, $credential);
            $state = $this->state($key);
            $now = now()->getTimestamp();
            if ($state['open_until'] > $now) {
                return false;
            }
            if ($state['probe_until'] > $now) {
                return false;
            }
            if ($state['open_until'] > 0) {
                $state['open_until'] = 0;
                $state['probe_until'] = $now + max(1, (int) config('luczor.proxy.circuit_breaker.probe_seconds', 15));
                $this->put($key, $state);
            }

            return true;
        });
    }

    public function recordSuccess(ModelProfile $profile, ProviderCredential $credential): void
    {
        Cache::forget($this->key($profile, $credential));
    }

    public function recordFailure(
        ModelProfile $profile,
        ProviderCredential $credential,
        ?int $status = null,
        ?string $retryAfter = null,
    ): void {
        if (! $this->countsAsHealthFailure($status)
            || ! (bool) config('luczor.proxy.circuit_breaker.enabled', true)) {
            return;
        }

        Cache::lock($this->key($profile, $credential).':lock', 5)->block(2, function () use ($profile, $credential, $status, $retryAfter): void {
            $key = $this->key($profile, $credential);
            $state = $this->state($key);
            $state['failures']++;
            $state['probe_until'] = 0;
            $threshold = max(1, (int) config('luczor.proxy.circuit_breaker.failure_threshold', 3));
            $retryDelay = $status === 429 ? $this->retryAfterSeconds($retryAfter) : null;
            if ($status === 429 || $state['failures'] >= $threshold) {
                $cooldown = $retryDelay ?? max(1, (int) config('luczor.proxy.circuit_breaker.cooldown_seconds', 60));
                $maximum = max(1, (int) config('luczor.proxy.circuit_breaker.max_retry_after_seconds', 900));
                $state['open_until'] = now()->getTimestamp() + min($cooldown, $maximum);
            }
            $this->put($key, $state);
        });
    }

    private function countsAsHealthFailure(?int $status): bool
    {
        return $status === null || $status === 0 || $status === 429 || ($status >= 500 && $status <= 599);
    }

    private function retryAfterSeconds(?string $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return max(1, (int) $value);
        }
        try {
            return max(1, (new DateTimeImmutable($value))->getTimestamp() - now()->getTimestamp());
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{failures:int,open_until:int,probe_until:int} */
    private function state(string $key): array
    {
        $state = Cache::get($key);

        return [
            'failures' => max(0, (int) (is_array($state) ? ($state['failures'] ?? 0) : 0)),
            'open_until' => max(0, (int) (is_array($state) ? ($state['open_until'] ?? 0) : 0)),
            'probe_until' => max(0, (int) (is_array($state) ? ($state['probe_until'] ?? 0) : 0)),
        ];
    }

    /** @param array{failures:int,open_until:int,probe_until:int} $state */
    private function put(string $key, array $state): void
    {
        $now = now()->getTimestamp();
        $ttl = max(
            60,
            (int) config('luczor.proxy.circuit_breaker.failure_window_seconds', 300),
            $state['open_until'] - $now,
            $state['probe_until'] - $now,
        );

        Cache::put(
            $key,
            $state,
            $ttl,
        );
    }

    private function key(ModelProfile $profile, ProviderCredential $credential): string
    {
        $identity = implode('|', [
            $profile->getKey(),
            $profile->provider,
            $profile->model_id,
            $credential->getKey(),
            $credential->provider,
            $credential->base_url,
            $credential->request_format,
        ]);

        return 'luczor:provider-circuit:v1:'.hash('sha256', $identity);
    }
}
