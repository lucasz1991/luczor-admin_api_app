<?php

namespace App\Services\Cognee;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for a self-hosted Cognee instance (behind Laravel).
 *
 * Endpoints are version-specific; adjust the constants to match the deployed
 * Cognee API. When no base URL is configured the client is "disabled" and all
 * calls become safe no-ops — the rest of the app keeps working.
 */
class CogneeClient
{
    private const ADD = '/api/v1/add';
    private const SEARCH = '/api/v1/search';
    private const COGNIFY = '/api/v1/cognify';
    private const DELETE = '/api/v1/delete';

    private ?Client $http = null;

    public function __construct(
        private string $baseUrl = '',
        private string $apiKey = '',
        private int $timeout = 15,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('luczor.cognee.base_url', ''),
            (string) config('luczor.cognee.api_key', ''),
            (int) config('luczor.cognee.timeout', 15),
        );
    }

    public function enabled(): bool
    {
        return $this->baseUrl !== '';
    }

    private function client(): Client
    {
        if (! $this->http) {
            $headers = ['Accept' => 'application/json'];
            if ($this->apiKey !== '') {
                $headers['Authorization'] = 'Bearer '.$this->apiKey;
            }
            $this->http = new Client([
                'base_uri' => $this->baseUrl,
                'timeout' => $this->timeout,
                'headers' => $headers,
            ]);
        }

        return $this->http;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload): array
    {
        if (! $this->enabled()) {
            return [];
        }

        try {
            $res = $this->client()->post($path, ['json' => $payload]);

            return json_decode((string) $res->getBody(), true) ?: [];
        } catch (GuzzleException $e) {
            Log::warning('Cognee call failed', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /** @param  array<string,mixed>  $metadata */
    public function add(string $dataset, string $content, array $metadata = []): array
    {
        return $this->post(self::ADD, [
            'dataset' => $dataset,
            'data' => $content,
            'metadata' => $metadata,
        ]);
    }

    /** @return array<int,mixed> */
    public function search(string $dataset, string $query, int $topK = 6): array
    {
        $res = $this->post(self::SEARCH, [
            'dataset' => $dataset,
            'query' => $query,
            'top_k' => $topK,
        ]);

        return $res['results'] ?? [];
    }

    public function cognify(string $dataset): array
    {
        return $this->post(self::COGNIFY, ['dataset' => $dataset]);
    }

    public function delete(string $dataset, string $id): array
    {
        return $this->post(self::DELETE, ['dataset' => $dataset, 'id' => $id]);
    }
}
