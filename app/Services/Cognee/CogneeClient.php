<?php

namespace App\Services\Cognee;

use App\Services\MemoryDlp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

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

    private const EXACT_DATA_LOOKUP = '/api/v1/luczor/data';

    private const RUNTIME = '/api/v1/luczor/runtime';

    private const LAUNCH_ACK = '/api/v1/luczor/launches/ack';

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
        private int $controlTimeout = 8,
        private int $ackTimeout = 3,
        private int $semanticQueryTimeout = 3,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->timeout = max(1, $this->timeout);
        $this->controlTimeout = max(1, min($this->timeout, $this->controlTimeout));
        $this->ackTimeout = max(1, min($this->controlTimeout, $this->ackTimeout));
        $this->semanticQueryTimeout = max(1, min($this->timeout, $this->semanticQueryTimeout));
        $this->http = $http;
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('luczor.cognee.base_url', ''),
            (string) config('luczor.cognee.api_key', ''),
            (int) config('luczor.cognee.timeout', 45),
            null,
            (int) config('luczor.cognee.control_timeout', 8),
            (int) config('luczor.cognee.ack_timeout', 3),
            (int) config('luczor.cognee.semantic_query_timeout', 3),
        );
    }

    public function enabled(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
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
            ...$this->timeoutOptions($this->controlTimeout),
        ], $throw);
    }

    /** @return array{add:string,cognify:string} */
    public function datasetStatus(string $datasetId, bool $throw = false): array
    {
        $query = 'dataset='.rawurlencode($datasetId)
            .'&pipeline=add_pipeline&pipeline=cognify_pipeline';
        $response = $this->getJson(self::DATASET_STATUS, $query, $throw, $this->controlTimeout);
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
            $this->controlTimeout,
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
            $this->controlTimeout,
        );

        return isset($response['pipeline_run_id']) ? $response : null;
    }

    /**
     * Recover every Cognee Data UUID created for one deterministic Luczor
     * upload. The wrapper serializes this lookup against all foreground Adds,
     * so an empty result proves an ambiguous Add did not commit.
     *
     * @return array{dataset_id:?string,data_ids:list<string>}
     */
    public function findData(
        string $dataset,
        int $memoryId,
        string $contentHash,
        bool $throw = false,
    ): array {
        $filename = $this->memoryFilename($memoryId, $contentHash);
        $response = $this->getJson(
            self::EXACT_DATA_LOOKUP,
            'dataset_name='.rawurlencode($dataset).'&name='.rawurlencode($filename),
            $throw,
            $this->controlTimeout,
        );
        $datasetId = strtolower(trim((string) ($response['dataset_id'] ?? '')));
        $dataIds = array_values(array_unique(array_filter(array_map(
            fn ($id) => strtolower(trim((string) $id)),
            is_array($response['data_ids'] ?? null) ? $response['data_ids'] : [],
        ), fn (string $id) => $this->isUuid($id))));
        $valid = ($datasetId === '' || $this->isUuid($datasetId))
            && ($dataIds === [] || $datasetId !== '')
            && count($dataIds) === count(is_array($response['data_ids'] ?? null) ? $response['data_ids'] : []);
        if (! $valid) {
            if ($throw) {
                throw new \RuntimeException('Cognee exact Data lookup returned an invalid identity response.');
            }

            return ['dataset_id' => null, 'data_ids' => []];
        }

        return [
            'dataset_id' => $datasetId !== '' ? $datasetId : null,
            'data_ids' => $dataIds,
        ];
    }

    public function observedInstanceId(): ?string
    {
        return $this->observedInstanceId;
    }

    public function observedLaunchInstanceId(): ?string
    {
        return $this->observedLaunchInstanceId;
    }

    /** Mark a durably persisted launch response reclaimable after retention. */
    public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Cognee idempotency key must be a lowercase SHA-256 value.');
        }

        $response = $this->request('POST', self::LAUNCH_ACK, [
            'headers' => ['X-Luczor-Idempotency-Key' => $idempotencyKey],
            ...$this->timeoutOptions($this->ackTimeout),
        ], $throw);

        return ($response['acknowledged'] ?? false) === true;
    }

    /** Return the authenticated Luczor wrapper boot UUID or fail closed. */
    public function probeRuntime(bool $throw = false): ?string
    {
        $response = $this->getJson(self::RUNTIME, '', $throw, $this->controlTimeout);
        $instanceId = strtolower(trim((string) ($response['instance_id'] ?? '')));
        if (! $this->isUuid($instanceId)
            || ! $this->observedInstanceId
            || ! hash_equals($instanceId, $this->observedInstanceId)) {
            if ($throw) {
                throw new \RuntimeException('Cognee runtime guard did not return a matching boot UUID.');
            }

            return null;
        }

        return $instanceId;
    }

    public function cognifyRunId(array $response, string $datasetId): ?string
    {
        $run = $response[$datasetId] ?? (count($response) === 1 ? reset($response) : null);
        if (! is_array($run)) {
            return null;
        }
        $responseDatasetId = strtolower(trim((string) ($run['dataset_id'] ?? '')));
        if (! $this->isUuid($responseDatasetId)
            || ! hash_equals(strtolower($datasetId), $responseDatasetId)) {
            return null;
        }
        $id = trim((string) ($run['pipeline_run_id'] ?? ''));

        return $this->isUuid($id) ? $id : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function search(string $dataset, string $query, int $topK = 6): array
    {
        try {
            return $this->searchDatasetsOrFail([$dataset], $query, $topK);
        } catch (\Throwable) {
            // Preserve the existing fail-closed adapter contract for direct
            // callers. Recall uses the throwing batch method so it can abort
            // semantic ranking and return its canonical SQL fallback.
            return [];
        }
    }

    /**
     * Search every authorized alias in one bounded provider request.
     *
     * @param  array<int,string>  $datasets
     * @return array<int,array<string,mixed>>
     */
    public function searchDatasetsOrFail(array $datasets, string $query, int $topK = 6): array
    {
        // Defense in depth: even a future direct adapter call cannot bypass
        // the orchestrator's semantic-query DLP boundary.
        if (! MemoryDlp::allowsExternalSemanticQuery($query)) {
            return [];
        }

        $datasets = array_values(array_unique(array_filter(array_map(
            static fn (mixed $dataset): string => trim((string) $dataset),
            $datasets,
        ), static fn (string $dataset): bool => $dataset !== '')));
        if ($datasets === []) {
            return [];
        }

        $response = $this->request('POST', self::SEARCH, [
            'json' => [
                'datasets' => $datasets,
                'query' => $query,
                'search_type' => 'CHUNKS',
                'top_k' => max(1, min(100, $topK)),
                'only_context' => false,
            ],
            ...$this->timeoutOptions($this->semanticQueryTimeout),
        ], true);

        return $this->normalizeSearchResults($response);
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
            ...$this->timeoutOptions($this->controlTimeout),
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

    /** @param array<string,mixed> $response */
    public function forgetSucceeded(array $response, string $expectedDataId): bool
    {
        $dataId = strtolower(trim((string) ($response['data_id'] ?? '')));
        $datasetId = strtolower(trim((string) ($response['dataset_id'] ?? '')));

        return ($response['status'] ?? null) === 'success'
            && $this->isUuid($dataId)
            && $this->isUuid($datasetId)
            && hash_equals(strtolower($expectedDataId), $dataId);
    }

    /** Extract an uploaded Data UUID from Cognee's ingestion response. */
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
    private function getJson(
        string $path,
        string $query,
        bool $throw = false,
        ?int $timeout = null,
    ): array {
        $options = ['query' => $query];
        if ($timeout !== null) {
            $options = array_merge($options, $this->timeoutOptions($timeout));
        }

        return $this->request('GET', $path, $options, $throw);
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

        // Never let a previous response authenticate the current call.
        $this->observedInstanceId = null;
        $this->observedLaunchInstanceId = null;

        try {
            $options['headers'] = array_merge($this->headers(), (array) ($options['headers'] ?? []));
            $response = $this->client()->request($method, $path, $options);
            $this->observeRuntimeHeaders($response);
            $decoded = json_decode((string) $response->getBody(), true);

            return is_array($decoded) ? $decoded : [];
        } catch (RequestException $error) {
            $response = $error->getResponse();
            $statusCode = $response?->getStatusCode() ?? 0;
            $decoded = [];
            if ($response) {
                $this->observeRuntimeHeaders($response);
                $body = json_decode((string) $response->getBody(), true);
                $decoded = is_array($body) ? $body : [];
            }
            // Keep the structured status/body available to the state machine,
            // but never chain Guzzle's exception: its rendered message may
            // include the complete provider response body and memory content.
            $failure = new CogneeRequestException($path, $statusCode, $decoded);
            $this->logFailure([
                'path' => $path,
                'status' => $statusCode ?: null,
                'exception_class' => $error::class,
            ]);

            if ($throw) {
                throw $failure;
            }

            return [];
        } catch (\Throwable $error) {
            $this->logFailure([
                'path' => $path,
                'exception_class' => $error::class,
            ]);

            if ($throw) {
                throw new \RuntimeException('Cognee projection request failed.');
            }

            return [];
        }
    }

    private function observeRuntimeHeaders(ResponseInterface $response): void
    {
        $instanceId = trim($response->getHeaderLine('X-Luczor-Cognee-Instance'));
        $this->observedInstanceId = $this->isUuid($instanceId)
            ? strtolower($instanceId)
            : null;
        $launchInstanceId = trim($response->getHeaderLine('X-Luczor-Cognee-Launch-Instance'));
        $this->observedLaunchInstanceId = $this->isUuid($launchInstanceId)
            ? strtolower($launchInstanceId)
            : null;
    }

    /** @return array{timeout:int,connect_timeout:int} */
    private function timeoutOptions(int $seconds): array
    {
        $seconds = max(1, $seconds);

        return [
            'timeout' => $seconds,
            'connect_timeout' => min(3, $seconds),
        ];
    }

    /** @param array<string,mixed> $context */
    private function logFailure(array $context): void
    {
        // The adapter is also exercised as a standalone unit without a booted
        // Laravel application. Production calls still use the configured log.
        if (function_exists('app') && app()->bound('log')) {
            Log::warning('Cognee call failed', $context);
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

    public function memoryFilename(int $memoryId, string $contentHash): string
    {
        $contentHash = strtolower($contentHash);
        if ($memoryId < 0 || ! preg_match('/^[a-f0-9]{64}$/', $contentHash)) {
            throw new \InvalidArgumentException('Cognee memory filename identity is invalid.');
        }

        return "luczor-memory-{$memoryId}-{$contentHash}.txt";
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
