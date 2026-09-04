<?php

namespace Tests\Unit;

use App\Models\NetworkPolicy;
use App\Services\ProviderHttpClientFactory;
use GuzzleHttp\Client;
use Tests\TestCase;

class ProviderHttpClientFactoryTest extends TestCase
{
    public function test_provider_client_disables_redirects_and_keeps_tls_verification_enabled(): void
    {
        $policy = new NetworkPolicy([
            'connect_timeout_ms' => 1000,
            'request_timeout_ms' => 2000,
        ]);

        $client = app(ProviderHttpClientFactory::class)->make($policy);

        $this->assertInstanceOf(Client::class, $client);
        $this->assertFalse($client->getConfig('allow_redirects'));
        $this->assertTrue($client->getConfig('verify'));
    }
}
