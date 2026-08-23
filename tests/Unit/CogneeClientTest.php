<?php

namespace Tests\Unit;

use App\Services\Cognee\CogneeClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class CogneeClientTest extends TestCase
{
    public function test_search_uses_the_cognee_1_4_chunks_contract_and_normalizes_acl_wrappers(): void
    {
        $history = [];
        $body = json_encode([[
            'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
            'dataset_name' => 'project-1',
            'dataset_tenant_id' => null,
            'search_result' => [[
                'id' => '074467ad-aa1c-41d7-9918-46f16e868720',
                'document_id' => '09393f2c-238c-4db8-853e-7e71fa2bd9bd',
                'text' => 'Remember this.',
            ]],
        ]], JSON_THROW_ON_ERROR);
        $client = $this->client([new Response(200, ['Content-Type' => 'application/json'], $body)], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'internal-service-key', 15, $client);

        $hits = $cognee->search('project-1', 'query', 9);

        $this->assertSame('09393f2c-238c-4db8-853e-7e71fa2bd9bd', $hits[0]['document_id']);
        $this->assertSame('project-1', $hits[0]['dataset_name']);
        $request = $history[0]['request'];
        $this->assertSame('/api/v1/search', $request->getUri()->getPath());
        $this->assertSame([
            'datasets' => ['project-1'],
            'query' => 'query',
            'search_type' => 'CHUNKS',
            'top_k' => 9,
            'only_context' => false,
        ], json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('Bearer internal-service-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('internal-service-key', $request->getHeaderLine('X-Api-Key'));
    }

    public function test_remember_uses_multipart_and_exposes_the_returned_data_uuid(): void
    {
        $history = [];
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'status' => 'completed',
                'items' => [['id' => $dataId]],
            ], JSON_THROW_ON_ERROR)),
        ], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $client);

        $response = $cognee->remember('project-1', 'My confirmed memory.', [
            'memory_link_id' => 42,
            'content_hash' => str_repeat('a', 64),
        ], true);

        $this->assertSame($dataId, $cognee->dataId($response));
        $request = $history[0]['request'];
        $requestBody = (string) $request->getBody();
        $this->assertSame('/api/v1/remember', $request->getUri()->getPath());
        $this->assertStringStartsWith('multipart/form-data;', $request->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('name="datasetName"', $requestBody);
        $this->assertStringContainsString('project-1', $requestBody);
        $this->assertStringContainsString('filename="luczor-memory-42-'.str_repeat('a', 64).'.txt"', $requestBody);
        $this->assertStringContainsString('My confirmed memory.', $requestBody);
    }

    public function test_add_guarded_cognify_and_exact_activity_use_the_cognee_1_4_contracts(): void
    {
        $history = [];
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $launchKey = hash('sha256', 'launch-once');
        $client = $this->client([
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-Luczor-Cognee-Instance' => $instanceId,
            ], json_encode([
                'status' => 'PipelineRunCompleted',
                'dataset_id' => $datasetId,
                'data_ingestion_info' => [['data_id' => $dataId]],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-Luczor-Cognee-Instance' => $instanceId,
                'X-Luczor-Cognee-Launch-Instance' => $instanceId,
            ], json_encode([
                $datasetId => [
                    'pipeline_run_id' => $runId,
                    'status' => 'PipelineRunStarted',
                    'dataset_id' => $datasetId,
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                $datasetId => [
                    'add_pipeline' => 'DATASET_PROCESSING_COMPLETED',
                    'cognify_pipeline' => 'DATASET_PROCESSING_IN_PROGRESS',
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-Luczor-Cognee-Instance' => $instanceId,
            ], json_encode([[
                'pipeline_name' => 'cognify_pipeline',
                'pipeline_run_id' => $runId,
                'dataset_id' => $datasetId,
                'status' => 'DATASET_PROCESSING_IN_PROGRESS',
            ]], JSON_THROW_ON_ERROR)),
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-Luczor-Cognee-Instance' => $instanceId,
            ], json_encode([
                'pipeline_name' => 'cognify_pipeline',
                'pipeline_run_id' => $runId,
                'dataset_id' => $datasetId,
                'status' => 'DATASET_PROCESSING_COMPLETED',
            ], JSON_THROW_ON_ERROR)),
        ], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $client);

        $add = $cognee->add('project-1', 'Confirmed memory.', [
            'memory_link_id' => 42,
            'content_hash' => str_repeat('a', 64),
        ], true);
        $cognify = $cognee->cognifyOnce('project-1', $launchKey, true);
        $this->assertSame($instanceId, $cognee->observedLaunchInstanceId());
        $status = $cognee->datasetStatus($datasetId, true);
        $runs = $cognee->pipelineRuns($datasetId, true);
        $run = $cognee->pipelineRun($datasetId, $runId, true);

        $this->assertSame($dataId, $cognee->dataId($add));
        $this->assertSame($datasetId, $cognee->datasetId($add));
        $this->assertSame($runId, $cognee->cognifyRunId($cognify, $datasetId));
        $this->assertSame($instanceId, $cognee->observedInstanceId());
        // A later status request clears the launch-specific header while the
        // current runtime identity remains observable.
        $this->assertNull($cognee->observedLaunchInstanceId());
        $this->assertSame([
            'add' => 'DATASET_PROCESSING_COMPLETED',
            'cognify' => 'DATASET_PROCESSING_IN_PROGRESS',
        ], $status);
        $this->assertSame($runId, $runs[0]['pipeline_run_id']);
        $this->assertSame('DATASET_PROCESSING_COMPLETED', $run['status']);
        $this->assertSame('/api/v1/add', $history[0]['request']->getUri()->getPath());
        $this->assertStringContainsString('name="run_in_background"', (string) $history[0]['request']->getBody());
        $this->assertStringContainsString("\r\nfalse\r\n", (string) $history[0]['request']->getBody());
        $this->assertSame('/api/v1/cognify', $history[1]['request']->getUri()->getPath());
        $this->assertSame([
            'datasets' => ['project-1'],
            'run_in_background' => true,
        ], json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($launchKey, $history[1]['request']->getHeaderLine('X-Luczor-Idempotency-Key'));
        $this->assertSame('/api/v1/datasets/status', $history[2]['request']->getUri()->getPath());
        $this->assertSame(
            "dataset={$datasetId}&pipeline=add_pipeline&pipeline=cognify_pipeline",
            $history[2]['request']->getUri()->getQuery()
        );
        $this->assertSame('/api/v1/activity/pipeline-runs', $history[3]['request']->getUri()->getPath());
        $this->assertSame('dataset_id='.$datasetId, $history[3]['request']->getUri()->getQuery());
        $this->assertSame('/api/v1/luczor/pipeline-runs/'.$runId, $history[4]['request']->getUri()->getPath());
        $this->assertSame('dataset_id='.$datasetId, $history[4]['request']->getUri()->getQuery());
    }

    public function test_improve_and_forget_use_the_cognee_1_4_payloads(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], '{}'),
            new Response(200, ['Content-Type' => 'application/json'], '{}'),
        ], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $client);
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';

        $cognee->improve('project-1', true);
        $cognee->forget('project-1', $dataId, true);

        $this->assertSame('/api/v1/improve', $history[0]['request']->getUri()->getPath());
        $this->assertSame([
            'dataset_name' => 'project-1',
            'run_in_background' => true,
        ], json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('/api/v1/forget', $history[1]['request']->getUri()->getPath());
        $this->assertSame([
            'dataset' => 'project-1',
            'data_id' => $dataId,
            'memory_only' => false,
        ], json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_guarded_improve_exposes_the_exact_run_and_launch_identity(): void
    {
        $history = [];
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $launchKey = hash('sha256', 'improve-once');
        $client = $this->client([
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-Luczor-Cognee-Instance' => $instanceId,
                'X-Luczor-Cognee-Launch-Instance' => $instanceId,
            ], json_encode([
                'pipeline_run_id' => $runId,
                'dataset_id' => $datasetId,
                'status' => 'PipelineRunStarted',
            ], JSON_THROW_ON_ERROR)),
        ], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $client);

        $response = $cognee->improveOnce('project-1', $launchKey, true);

        $this->assertSame($runId, $cognee->pipelineInfoRunId($response));
        $this->assertSame($datasetId, $cognee->datasetId($response));
        $this->assertSame($instanceId, $cognee->observedInstanceId());
        $this->assertSame($instanceId, $cognee->observedLaunchInstanceId());
        $this->assertSame('/api/v1/improve', $history[0]['request']->getUri()->getPath());
        $this->assertSame($launchKey, $history[0]['request']->getHeaderLine('X-Luczor-Idempotency-Key'));
        $this->assertSame([
            'dataset_name' => 'project-1',
            'run_in_background' => true,
        ], json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int,Response>  $responses
     * @param  array<int,array<string,mixed>>  $history
     */
    private function client(array $responses, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }
}
