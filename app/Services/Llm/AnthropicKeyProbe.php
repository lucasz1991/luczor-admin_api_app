<?php

namespace App\Services\Llm;

use App\Models\NetworkPolicy;
use App\Services\ProviderHttpClientFactory;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Validates an Anthropic API key against the read-only model list. The key
 * never appears in return values, logs or exception messages.
 */
class AnthropicKeyProbe
{
    public const INVALID = 'claude_key_invalid';

    public const UNREACHABLE = 'claude_unreachable';

    public function __construct(private ProviderHttpClientFactory $httpClients) {}

    /** Returns null when the key is accepted, otherwise a stable error code. */
    public function check(#[\SensitiveParameter] string $apiKey, ?string $baseUrl = null): ?string
    {
        $driver = new AnthropicMessagesDriver;
        $baseUrl = rtrim(trim((string) $baseUrl) ?: $driver->defaultBaseUrl(), '/');
        if (parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
            return self::UNREACHABLE;
        }

        // Short, fixed limits: this is an interactive settings check.
        $client = $this->httpClients->make(new NetworkPolicy([
            'key' => 'provider-key.probe',
            'connect_timeout_ms' => 5000,
            'request_timeout_ms' => 15000,
        ]));

        try {
            $response = $client->request('GET', $baseUrl.'/models', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => AnthropicMessagesDriver::VERSION,
                    'Accept' => 'application/json',
                ],
                'query' => ['limit' => 1],
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            // Deliberately neither rethrown nor logged: transport exceptions
            // can carry request headers.
            return self::UNREACHABLE;
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return null;
        }

        return in_array($status, [401, 403], true) ? self::INVALID : self::UNREACHABLE;
    }
}
