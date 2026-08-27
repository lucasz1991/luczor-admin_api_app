<?php

namespace Tests\Unit;

use App\Services\Cognee\CogneeClient;
use App\Services\Cognee\CogneeRequestException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CogneeClientTest extends TestCase
{
    public function test_from_config_reads_the_service_key_from_an_absolute_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'luczor-cognee-key-');
        $this->assertIsString($path);
        file_put_contents($path, 'file-service-key');

        try {
            config([
                'luczor.cognee.base_url' => 'http://127.0.0.1:8010',
                'luczor.cognee.api_key' => 'ignored-env-key',
                'luczor.cognee.api_key_file' => $path,
            ]);

            $this->assertTrue(CogneeClient::fromConfig()->enabled());
        } finally {
            @unlink($path);
        }
    }

    public function test_from_config_fails_closed_for_an_unreadable_key_file(): void
    {
        config([
            'luczor.cognee.base_url' => 'http://127.0.0.1:8010',
            'luczor.cognee.api_key' => 'must-not-fallback',
            'luczor.cognee.api_key_file' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-luczor-cognee-key',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a readable absolute file');

        CogneeClient::fromConfig();
    }

    public function test_disabled_cognee_does_not_require_a_configured_key_file(): void
    {
        config([
            'luczor.cognee.base_url' => '',
            'luczor.cognee.api_key_file' => 'relative/missing/key',
        ]);

        $this->assertFalse(CogneeClient::fromConfig()->enabled());
    }

    public function test_search_uses_the_cognee_1_4_chunks_contract_and_normalizes_acl_wrappers(): void
    {
        $history = [];
        $body = json_encode([[
            'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
            'dataset_name' => 'project-1',
            'dataset_tenant_id' => null,
            'text_result' => null,
            'context_result' => 'Remember this.',
            'objects_result' => [[
                'id' => '074467ad-aa1c-41d7-9918-46f16e868720',
                'score' => 0.97,
                'payload' => [
                    'id' => '2288b800-848a-4189-b051-d45d2c14db47',
                    'document_id' => '09393f2c-238c-4db8-853e-7e71fa2bd9bd',
                    'text' => 'Remember this.',
                ],
            ]],
        ]], JSON_THROW_ON_ERROR);
        $client = $this->client([new Response(200, ['Content-Type' => 'application/json'], $body)], $history);
        $cognee = new CogneeClient(
            'http://cognee:8000',
            'internal-service-key',
            15,
            $client,
            semanticQueryTimeout: 2,
        );

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
            'only_context' => true,
            'verbose' => true,
        ], json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('Bearer internal-service-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('internal-service-key', $request->getHeaderLine('X-Api-Key'));
        $this->assertSame(2, $history[0]['options']['timeout']);
        $this->assertSame(2, $history[0]['options']['connect_timeout']);
    }

    public function test_legacy_direct_remember_is_not_exposed(): void
    {
        $this->assertFalse(method_exists(CogneeClient::class, 'remember'));
    }

    public function test_direct_search_fails_closed_for_sensitive_query_without_http_egress(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $client);

        $this->assertSame([], $cognee->search('project-1', 'Kontonummer DE89370400440532013000'));
        $this->assertSame([], $history);
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
        $this->assertSame(8, $history[1]['options']['timeout']);
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

    public function test_cognify_run_id_rejects_a_single_cached_run_from_another_dataset(): void
    {
        $expectedDataset = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $otherDataset = '5f950d9b-5538-4d11-82f9-d916d1ea0777';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $cognee = new CogneeClient('http://cognee:8000', 'key');

        $this->assertNull($cognee->cognifyRunId([
            $otherDataset => [
                'pipeline_run_id' => $runId,
                'dataset_id' => $otherDataset,
            ],
        ], $expectedDataset));
    }

    public function test_exact_data_lookup_uses_the_deterministic_filename_and_validates_uuids(): void
    {
        $history = [];
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $hash = str_repeat('a', 64);
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'dataset_id' => $datasetId,
                'data_ids' => [$dataId],
            ], JSON_THROW_ON_ERROR)),
        ], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $client);

        $this->assertSame([
            'dataset_id' => $datasetId,
            'data_ids' => [$dataId],
        ], $cognee->findData('project-1', 42, $hash, true));
        $this->assertSame('/api/v1/luczor/data', $history[0]['request']->getUri()->getPath());
        $this->assertSame(
            'dataset_name=project-1&name=luczor-memory-42-'.$hash.'.txt',
            $history[0]['request']->getUri()->getQuery(),
        );
    }

    public function test_forget_uses_the_cognee_1_4_payload(): void
    {
        $history = [];
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'status' => 'success',
                'data_id' => $dataId,
                'dataset_id' => $datasetId,
            ], JSON_THROW_ON_ERROR)),
        ], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $client);

        $response = $cognee->forget('project-1', $dataId, true);

        $this->assertTrue($cognee->forgetSucceeded($response, $dataId));
        $this->assertSame('/api/v1/forget', $history[0]['request']->getUri()->getPath());
        $this->assertSame([
            'dataset' => 'project-1',
            'data_id' => $dataId,
            'memory_only' => false,
        ], json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_forget_acknowledgement_fails_closed_for_an_empty_or_wrong_response(): void
    {
        $cognee = new CogneeClient('http://cognee:8000', 'key');
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';

        $this->assertFalse($cognee->forgetSucceeded([], $dataId));
        $this->assertFalse($cognee->forgetSucceeded([
            'status' => 'success',
            'data_id' => '88588824-946d-43de-86ee-17ee4212ca65',
            'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
        ], $dataId));
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
            ], json_encode([
                'instance_id' => $instanceId,
                'guarded_operations' => ['cognify', 'improve'],
            ], JSON_THROW_ON_ERROR)),
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

        $this->assertSame($instanceId, $cognee->probeRuntime(true));
        $response = $cognee->improveOnce('project-1', $launchKey, true);

        $this->assertSame($runId, $cognee->pipelineInfoRunId($response));
        $this->assertSame($datasetId, $cognee->datasetId($response));
        $this->assertSame($instanceId, $cognee->observedInstanceId());
        $this->assertSame($instanceId, $cognee->observedLaunchInstanceId());
        $this->assertSame('/api/v1/luczor/runtime', $history[0]['request']->getUri()->getPath());
        $this->assertSame('/api/v1/improve', $history[1]['request']->getUri()->getPath());
        $this->assertSame($launchKey, $history[1]['request']->getHeaderLine('X-Luczor-Idempotency-Key'));
        $this->assertSame([
            'dataset_name' => 'project-1',
            'run_in_background' => true,
        ], json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_missing_runtime_header_clears_a_previously_observed_instance(): void
    {
        $history = [];
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $client = $this->client([
            new Response(200, ['X-Luczor-Cognee-Instance' => $instanceId], json_encode([
                'instance_id' => $instanceId,
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], '[]'),
        ], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $client);

        $this->assertSame($instanceId, $cognee->probeRuntime(true));
        $this->assertSame([], $cognee->search('project-1', 'query'));
        $this->assertNull($cognee->observedInstanceId());
        $this->assertNull($cognee->observedLaunchInstanceId());
    }

    public function test_launch_ack_uses_the_authenticated_idempotency_key_contract(): void
    {
        $history = [];
        $launchKey = hash('sha256', 'persisted-launch');
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], '{"acknowledged":true}'),
        ], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $client);

        $this->assertTrue($cognee->acknowledgeLaunch($launchKey, true));
        $this->assertSame('/api/v1/luczor/launches/ack', $history[0]['request']->getUri()->getPath());
        $this->assertSame($launchKey, $history[0]['request']->getHeaderLine('X-Luczor-Idempotency-Key'));
        $this->assertSame('Bearer key', $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(3, $history[0]['options']['timeout']);
    }

    public function test_guarded_http_rejection_preserves_status_and_terminal_contract(): void
    {
        $history = [];
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $client = $this->client([
            new Response(420, [
                'Content-Type' => 'application/json',
                'X-Luczor-Cognee-Instance' => $instanceId,
                'X-Luczor-Cognee-Launch-Instance' => $instanceId,
            ], json_encode([
                'pipeline_run_id' => '744a537f-bb81-4637-8287-79b5c55f0913',
                'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                'status' => 'PipelineRunErrored',
            ], JSON_THROW_ON_ERROR)),
        ], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $client);

        try {
            $cognee->improveOnce('project-1', hash('sha256', 'terminal-improve'), true);
            $this->fail('Cognee HTTP 420 must remain a typed terminal rejection.');
        } catch (CogneeRequestException $error) {
            $this->assertSame(420, $error->statusCode());
            $this->assertTrue($error->isTerminalImproveFailure());
            $this->assertSame('PipelineRunErrored', $error->response()['status']);
        }

        $this->assertSame($instanceId, $cognee->observedInstanceId());
        $this->assertSame($instanceId, $cognee->observedLaunchInstanceId());
    }

    public function test_provider_error_body_is_absent_from_logs_and_exception_chain(): void
    {
        Log::spy();
        $history = [];
        $marker = 'PRIVATE-MEMORY-MARKER-DO-NOT-LOG';
        $client = $this->client([
            new Response(422, ['Content-Type' => 'application/json'], json_encode([
                'detail' => $marker,
            ], JSON_THROW_ON_ERROR)),
        ], $history);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $client);

        try {
            $cognee->improveOnce('project-1', hash('sha256', 'sanitized-error'), true);
            $this->fail('The typed Cognee rejection was not thrown.');
        } catch (CogneeRequestException $error) {
            $this->assertSame($marker, $error->response()['detail']);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString($marker, (string) $error);
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($marker) {
            return $message === 'Cognee call failed'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $marker)
                && isset($context['exception_class'])
                && ! isset($context['error']);
        });
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
