<?php

namespace App\Services;

use App\Models\NetworkPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/** Creates the outbound provider client from the active server policy. */
class ProviderHttpClientFactory
{
    public function make(NetworkPolicy $policy): ClientInterface
    {
        return new Client([
            'connect_timeout' => max(1, (int) $policy->connect_timeout_ms / 1000),
            'timeout' => max(1, (int) $policy->request_timeout_ms / 1000),
        ]);
    }
}
