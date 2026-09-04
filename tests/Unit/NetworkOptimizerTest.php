<?php

namespace Tests\Unit;

use App\Exceptions\RoutingPolicyException;
use App\Models\NetworkPolicy;
use App\Services\NetworkOptimizer;
use PHPUnit\Framework\TestCase;

class NetworkOptimizerTest extends TestCase
{
    public function test_only_explicit_safe_transport_and_http_statuses_are_accepted(): void
    {
        $policy = new NetworkPolicy([
            'config' => ['retry_statuses' => [0, 408, 409, 425, 429, 500, 529, 599]],
        ]);

        $this->assertSame(
            [0, 408, 409, 425, 429, 500, 529, 599],
            (new NetworkOptimizer)->retryStatuses($policy),
        );
    }

    public function test_informational_success_redirect_unsafe_client_and_out_of_range_statuses_fail_closed(): void
    {
        foreach ([100, 200, 302, 418, 600, -1] as $status) {
            $policy = new NetworkPolicy(['config' => ['retry_statuses' => [$status]]]);

            try {
                (new NetworkOptimizer)->retryStatuses($policy);
                $this->fail("Retry status {$status} must fail closed.");
            } catch (RoutingPolicyException $exception) {
                $this->assertSame('routing_network_policy_retry_statuses_invalid', $exception->reasonCode);
            }
        }
    }
}
