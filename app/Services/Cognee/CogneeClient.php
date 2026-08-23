<?php

namespace App\Services\Cognee;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * HTTP adapter for the Cognee 1.4 API pinned by services/cognee/Dockerfile.
 *
 * Cognee remains a rebuildable retrieval projection. Dataset authorization and
 * the final memory result are always decided from Luczor's canonical SQL rows.
 */
class CogneeClient
{
    private const ADD = '/api/v1/add';

    private const COGNIFY = '/api/v1/cognify';

    private const DATASET_STATUS = '/api/v1/datasets/status';

    private const ACTIVITY_RUNS = '/api/v1/activity/pipeline-runs';

    private const EXACT_PIPELINE_RUN = '/api/v1/luczor/pipeline-runs/';

    private const REMEMBER = '/api/v1/remember';

    private const SEARCH = '/api/v1/search';

    private const IMPROVE = '/api/v1/improve';

    private const FORGET = '/api/v1/forget';

    private ?Client $http;

    private ?string $observedInstanceId = null;

    private ?string $observedLaunchInstanceId = null;

    public function __construct(
        private string $baseUrl = '',
        private string $apiKey = '',
        private int $timeout = 15,
        ?Client $http = null,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->http = $http;
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
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /**
     * Project one confirmed Luczor memory into Cognee.
     *
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function remember(string $dataset, string $content, array $metadata = [], bool $throw = false): array
    {
        $memoryId = max(0, (int) ($metadata['memory_link_id'] ?? 0));
        $contentHash = strtolower((string) ($metadata['content_hash'] ?? hash('sha256', $this->normalizeText($content))));
        if (! preg_match('/^[a-f0-9]{64}$/', $contentHash)) {
            $contentHash = hash('sha256', $this->normalizeText($content));
        }

        // This non-sensitive filename provides an additional diagnostic link.
        // The authoritative result mapping uses Cognee's Data UUID instead.
        $filename = "luczor-memory-{$memoryId}-{$contentHash}.txt";

        return $this->postMultipart(self::REMEMBER, [
            [
                'name' => 'datasetName',
                'contents' => $dataset,
            ],
            [
                'name' => 'run_in_background',
                'contents' => 'false',
            ],
            [
                'name' => 'data',
                'contents' => $content,
                'filename' => $filename,
                'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
            ],
        ], $throw);
    }

    /**
     * Ingest one small confirmed memory without running the LLM graph build.
     * Cognify is launched separately in background so a queue worker never
     * blocks for the minutes Cognee 1.4 documents for `/remember`.
     *
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function add(string $dataset, string $content, array $metadata = [], bool $throw = false): array
    {
        $memoryId = max(0, (int) ($metadata['memory_link_id'] ?? 0));
        $contentHash = $this->contentHash($content, $metadata);

        return $this->postMultipart(self::ADD, [
            ['name' => 'datasetName', 'contents' => $dataset],
            ['name' => 'run_in_background', 'contents' => 'false'],
            [
                'name' => 'data',
                'contents' => $content,
                'filename' => $this->memoryFilename($memoryId, $contentHash),
                'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
            ],
        ], $throw);
    }

    /** Launch Cognee 1.4 graph construction and return immediately. */
    public function cognify(string $dataset, bool $throw = false): array
    {
        return $this->postJson(self::COGNIFY, [
            'datasets' => [$dataset],
            'run_in_background' => true,
        ], $throw);
    }

    /** Launch one idempotency-guarded Cognee background generation. */
    public function cognifyOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Cognee idempotency key must be a lowercase SHA-256 value.');
        }

        return $this->request('POST', self::COGNIFY, [
            'json' => [
                'datasets' => [$dataset],
                'run_in_background' => true,
            ],
            'headers' => ['X-Luczor-Idempotency-Key' => $idempotencyKey],
        ], $throw);
    }

    /** @return array{add:string,cognify:string} */
    public function datasetStatus(string $datasetId, bool $throw = false): array
    {
        $query = 'dataset='.rawurlencode($datasetId)
            .'&pipeline=add_pipeline&pipeline=cognify_pipeline';
        $response = $this->getJson(self::DATASET_STATUS, $query, $throw);
        $status = $response[$datasetId] ?? (count($response) === 1 ? reset($response) : null);
        if (is_string($status)) {
            return ['add' => 'unknown', 'cognify' => $status];
        }
        if (! is_array($status)) {
            return ['add' => 'unknown', 'cognify' => 'unknown'];
        }

        return [
            'add' => (string) ($status['add_pipeline'] ?? 'unknown'),
            'cognify' => (string) ($status['cognify_pipeline'] ?? 'unknown'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function pipelineRuns(string $datasetId, bool $throw = false): array
    {
        $response = $this->getJson(
            self::ACTIVITY_RUNS,
            'dataset_id='.rawurlencode($datasetId),
            $throw,
        );

        return array_values(array_filter($response, fn ($row) => is_array($row)));
    }

    /** @return array<string,mixed>|null */
    public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
    {
        if (! $this->isUuid($datasetId) || ! $this->isUuid($runId)) {
            throw new \InvalidArgumentException('Cognee dataset and pipeline run IDs must be UUIDs.');
        }

        $response = $this->getJson(
            self::EXACT_PIPELINE_RUN.rawurlencode($runId),
            'dataset_id='.rawurlencode($datasetId),
            $throw,
        );

        return isset($response['pipeline_run_id']) ? $response : null;
    }

    public function observedInstanceId(): ?string
    {
        return $this->observedInstanceId;
    }

    public function observedLaunchInstanceId(): ?string
    {
        return $this->observedLaunchInstanceId;
    }

    public function cognifyRunId(array $response, string $datasetId): ?string
    {
        $run = $response[$datasetId] ?? (count($response) === 1 ? reset($response) : null);
        if (! is_array($run)) {
            return null;
        }
        $id = trim((string) ($run['pipeline_run_id'] ?? ''));

        return $this->isUuid($id) ? $id : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function search(string $dataset, string $query, int $topK = 6): array
    {
        $response = $this->postJson(self::SEARCH, [
            'datasets' => [$dataset],
            'query' => $query,
            'search_type' => 'CHUNKS',
            'top_k' => max(1, min(100, $topK)),
            'only_context' => false,
        ]);

        return $this->normalizeSearchResults($response);
    }

    /** @return array<string,mixed> */
    public function improve(string $dataset, bool $throw = false): array
    {
        return $this->postJson(self::IMPROVE, [
            'dataset_name' => $dataset,
            'run_in_background' => true,
        ], $throw);
    }

    /** Launch one idempotency-guarded Cognee improvement. */
    public function improveOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Cognee idempotency key must be a lowercase SHA-256 value.');
        }

        return $this->request('POST', self::IMPROVE, [
            'json' => [
                'dataset_name' => $dataset,
                'run_in_background' => true,
            ],
            'headers' => ['X-Luczor-Idempotency-Key' => $idempotencyKey],
        ], $throw);
    }

    public function pipelineInfoRunId(array $response): ?string
    {
        $id = trim((string) ($response['pipeline_run_id'] ?? ''));

        return $this->isUuid($id) ? $id : null;
    }

    /** @return array<string,mixed> */
    public function forget(string $dataset, string $dataId, bool $throw = false): array
    {
        return $this->postJson(self::FORGET, [
            'dataset' => $dataset,
            'data_id' => $dataId,
            'memory_only' => false,
        ], $throw);
    }

    /** Extract the uploaded Data UUID returned by blocking /remember. */
    public function dataId(array $response): ?string
    {
        foreach (['items', 'payload', 'data_ingestion_info'] as $key) {
            $items = $response[$key] ?? [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach (['id', 'data_id'] as $idKey) {
                    $id = trim((string) ($item[$idKey] ?? ''));
                    if ($this->isUuid($id)) {
                        return $id;
                    }
                }
            }
        }

        return null;
    }

    public function datasetId(array $response): ?string
    {
        $id = trim((string) ($response['dataset_id'] ?? ''));

        return $this->isUuid($id) ? $id : null;
    }

    private function client(): Client
    {
        if (! $this->http) {
            $this->http = new Client([
                'base_uri' => $this->baseUrl,
                'connect_timeout' => min(5, $this->timeout),
                'timeout' => $this->timeout,
            ]);
        }

        return $this->http;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->apiKey,
            'X-Api-Key' => $this->apiKey,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<mixed>
     */
    private function postJson(string $path, array $payload, bool $throw = false): array
    {
        return $this->request('POST', $path, ['json' => $payload], $throw);
    }

    /**
     * @param  array<int,array<string,mixed>>  $parts
     * @return array<mixed>
     */
    private function postMultipart(string $path, array $parts, bool $throw = false): array
    {
        return $this->request('POST', $path, ['multipart' => $parts], $throw);
    }

    /** @return array<mixed> */
    private function getJson(string $path, string $query, bool $throw = false): array
    {
        return $this->request('GET', $path, ['query' => $query], $throw);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<mixed>
     */
    private function request(string $method, string $path, array $options, bool $throw): array
    {
        if (! $this->enabled()) {
            return [];
        }

        try {
            $options['headers'] = array_merge($this->headers(), (array) ($options['headers'] ?? []));
            $response = $this->client()->request($method, $path, $options);
            $instanceId = trim($response->getHeaderLine('X-Luczor-Cognee-Instance'));
            if ($this->isUuid($instanceId)) {
                $this->observedInstanceId = strtolower($instanceId);
            }
            $launchInstanceId = trim($response->getHeaderLine('X-Luczor-Cognee-Launch-Instance'));
            $this->observedLaunchInstanceId = $this->isUuid($launchInstanceId)
                ? strtolower($launchInstanceId)
                : null;
            $decoded = json_decode((string) $response->getBody(), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $error) {
            Log::warning('Cognee call failed', [
                'path' => $path,
                'error' => $error->getMessage(),
            ]);

            if ($throw) {
                throw new \RuntimeException('Cognee projection failed: '.$error->getMessage(), 0, $error);
            }

            return [];
        }
    }

    /**
     * Cognee 1.4 returns one wrapper per authorized dataset. CHUNKS results
     * live below `search_result`; installations without backend ACL may return
     * the chunk list directly. Normalize both contracts here.
     *
     * @param  array<mixed>  $response
     * @return array<int,array<string,mixed>>
     */
    private function normalizeSearchResults(array $response): array
    {
        $normalized = [];
        foreach ($response as $row) {
            if (! is_array($row)) {
                if (is_string($row) && trim($row) !== '') {
                    $normalized[] = ['text' => $row];
                }

                continue;
            }

            $datasetMeta = array_filter([
                'dataset_id' => $row['dataset_id'] ?? null,
                'dataset_name' => $row['dataset_name'] ?? null,
                'dataset_tenant_id' => $row['dataset_tenant_id'] ?? null,
            ], fn ($value) => $value !== null);
            $result = $row['search_result'] ?? $row;
            $hits = is_array($result) && array_is_list($result) ? $result : [$result];

            foreach ($hits as $hit) {
                if (is_string($hit)) {
                    $hit = ['text' => $hit];
                }
                if (! is_array($hit)) {
                    continue;
                }

                $normalized[] = array_merge($datasetMeta, $hit);
            }
        }

        return $normalized;
    }

    private function normalizeText(string $content): string
    {
        return preg_replace('/\s+/u', ' ', trim($content)) ?? trim($content);
    }

    /** @param array<string,mixed> $metadata */
    private function contentHash(string $content, array $metadata): string
    {
        $contentHash = strtolower((string) ($metadata['content_hash'] ?? hash('sha256', $this->normalizeText($content))));

        return preg_match('/^[a-f0-9]{64}$/', $contentHash)
            ? $contentHash
            : hash('sha256', $this->normalizeText($content));
    }

    private function memoryFilename(int $memoryId, string $contentHash): string
    {
        return "luczor-memory-{$memoryId}-{$contentHash}.txt";
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
