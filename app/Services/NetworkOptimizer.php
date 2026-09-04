<?php

namespace App\Services;

use App\Exceptions\RoutingPolicyException;
use App\Models\NetworkPolicy;

class NetworkOptimizer
{
    public function policy(?string $key = null): NetworkPolicy
    {
        if (! is_string($key) || trim($key) === '') {
            throw new RoutingPolicyException('routing_network_policy_unavailable');
        }

        $policy = NetworkPolicy::query()
            ->where('status', 'active')
            ->where('key', $key)
            ->first();
        if (! $policy
            || (int) $policy->connect_timeout_ms < 1
            || (int) $policy->request_timeout_ms < 1
            || (int) $policy->max_attempts < 1) {
            throw new RoutingPolicyException('routing_network_policy_unavailable');
        }

        return $policy;
    }

    public function shouldRetry(int $status, int $attempt, int $availableCandidates, NetworkPolicy $policy): bool
    {
        return $attempt < min($availableCandidates, (int) $policy->max_attempts)
            && in_array($status, $this->retryStatuses($policy), true);
    }

    /** @return array<int,int> */
    public function retryStatuses(NetworkPolicy $policy): array
    {
        $configured = is_array($policy->config) ? ($policy->config['retry_statuses'] ?? null) : null;
        if (! is_array($configured) || ! array_is_list($configured) || $configured === []) {
            throw new RoutingPolicyException('routing_network_policy_retry_statuses_invalid');
        }

        $statuses = [];
        foreach ($configured as $status) {
            if (! is_int($status) || ! $this->safeRetryStatus($status)) {
                throw new RoutingPolicyException('routing_network_policy_retry_statuses_invalid');
            }
            $statuses[] = $status;
        }

        return array_values(array_unique($statuses));
    }

    private function safeRetryStatus(int $status): bool
    {
        return $status === 0
            || in_array($status, [408, 409, 425, 429], true)
            || ($status >= 500 && $status <= 599);
    }

    public function backoff(NetworkPolicy $policy, int $attempt): void
    {
        $base = max(0, (int) $policy->backoff_ms);
        if ($base > 0) {
            usleep(($base * (2 ** max(0, $attempt - 1)) + random_int(0, 100)) * 1000);
        }
    }
}
