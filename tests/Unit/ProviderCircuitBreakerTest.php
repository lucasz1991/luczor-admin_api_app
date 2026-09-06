<?php

namespace Tests\Unit;

use App\Models\ModelProfile;
use App\Models\ProviderCredential;
use App\Services\ProviderCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ProviderCircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('cache.default', 'array');
        Config::set('luczor.proxy.circuit_breaker', [
            'enabled' => true,
            'failure_threshold' => 2,
            'failure_window_seconds' => 300,
            'cooldown_seconds' => 60,
            'max_retry_after_seconds' => 300,
            'probe_seconds' => 15,
        ]);
        Cache::flush();
        $this->travelTo('2026-09-06 12:00:00');
    }

    public function test_transport_and_server_failures_open_then_allow_one_half_open_probe(): void
    {
        [$profile, $credential] = $this->identity();
        $breaker = app(ProviderCircuitBreaker::class);

        $breaker->recordFailure($profile, $credential);
        $this->assertTrue($breaker->allows($profile, $credential));
        $breaker->recordFailure($profile, $credential, 503);
        $this->assertFalse($breaker->allows($profile, $credential));

        $this->travel(61)->seconds();
        $this->assertTrue($breaker->allows($profile, $credential));
        $this->assertFalse($breaker->allows($profile, $credential));
        $breaker->recordSuccess($profile, $credential);
        $this->assertTrue($breaker->allows($profile, $credential));
    }

    public function test_rate_limit_retry_after_opens_immediately_and_is_bounded(): void
    {
        [$profile, $credential] = $this->identity();
        $breaker = app(ProviderCircuitBreaker::class);

        $breaker->recordFailure($profile, $credential, 429, '120');
        $this->travel(119)->seconds();
        $this->assertFalse($breaker->allows($profile, $credential));
        $this->travel(2)->seconds();
        $this->assertTrue($breaker->allows($profile, $credential));

        $breaker->recordSuccess($profile, $credential);
        $breaker->recordFailure($profile, $credential, 429, '9999');
        $this->travel(301)->seconds();
        $this->assertTrue($breaker->allows($profile, $credential));
    }

    public function test_retry_after_state_outlives_a_short_failure_window(): void
    {
        Config::set('luczor.proxy.circuit_breaker.failure_window_seconds', 60);
        [$profile, $credential] = $this->identity();
        $breaker = app(ProviderCircuitBreaker::class);

        $breaker->recordFailure($profile, $credential, 429, '120');
        $this->travel(61)->seconds();

        $this->assertFalse($breaker->allows($profile, $credential));
    }

    public function test_client_errors_do_not_poison_provider_health(): void
    {
        [$profile, $credential] = $this->identity();
        $breaker = app(ProviderCircuitBreaker::class);

        $breaker->recordFailure($profile, $credential, 400);
        $breaker->recordFailure($profile, $credential, 401);
        $this->assertTrue($breaker->allows($profile, $credential));
    }

    /** @return array{ModelProfile,ProviderCredential} */
    private function identity(): array
    {
        $profile = new ModelProfile;
        $profile->setAttribute('id', 41);
        $credential = new ProviderCredential;
        $credential->setAttribute('id', 73);

        return [$profile, $credential];
    }
}
