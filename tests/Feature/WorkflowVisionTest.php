<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\LlmAttempt;
use App\Models\LlmExperiment;
use App\Models\LlmRun;
use App\Models\ModelProfile;
use App\Models\ModelRanking;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\NetworkPolicy;
use App\Models\Project;
use App\Models\ProviderCredential;
use App\Models\ProviderPriceSnapshot;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowOperation;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\AuditLogger;
use App\Services\DeviceJobSigner;
use App\Services\ProviderHttpClientFactory;
use App\Services\Proxy\WorkflowVisionPolicy;
use App\Services\Proxy\WorkflowVisionService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WorkflowVisionTest extends TestCase
{
    use RefreshDatabase;

    private ModelProfile $profile;

    private WorkflowRun $root;

    private WorkflowStep $step;

    private DeviceJob $job;

    protected function setUp(): void
    {
        parent::setUp();
        $resource = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($resource, $privateKey);
        Config::set('luczor.device_jobs.private_key_file', '');
        Config::set('luczor.device_jobs.private_key', $privateKey);
        Config::set('queue.default', 'sync');
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_id' => 'vision-device', 'name' => 'Fixture', 'status' => 'online']);
        $key = ApiKey::mint(['user_id' => $user->id, 'device_id' => $device->device_id, 'name' => 'Vision test', 'abilities' => ['proxy.use'], 'active' => true]);
        $this->withHeader('X-Api-Key', $key['plain']);
        $credential = ProviderCredential::create(['provider' => 'openrouter', 'label' => 'Fixture', 'api_key' => 'fixture-secret', 'request_format' => 'chat_completions', 'active' => true]);
        $this->profile = ModelProfile::create(['name' => 'Fixture vision', 'slug' => 'fixture-vision', 'model_id' => 'fixture/vision', 'provider' => 'openrouter',
            'provider_credential_id' => $credential->id, 'active' => true, 'max_tokens' => 128, 'context_window' => 10000,
            'capabilities' => ['vision'], 'meta' => ['multimodal_contract' => ['version' => 1, 'input_format' => 'png_data_url', 'max_image_bytes' => 2097152, 'max_pixels' => 16000000, 'input_tokens_per_image' => 1024]]]);
        ProviderPriceSnapshot::create(['provider_id' => 'openrouter', 'model_id' => 'fixture/vision', 'currency' => 'USD', 'input_per_million' => 1, 'output_per_million' => 2, 'valid_from' => now()->subMinute(), 'source' => 'fixture']);
        $case = ModelUseCase::create(['name' => 'Vision', 'slug' => 'vision', 'active' => true, 'network_policy_key' => 'vision-test', 'max_attempts' => 2, 'routing_strategy' => 'manual']);
        ModelUseCaseEntry::create(['model_use_case_id' => $case->id, 'model_profile_id' => $this->profile->id, 'active' => true, 'sort_order' => 1]);
        NetworkPolicy::create(['key' => 'vision-test', 'name' => 'Vision', 'status' => 'active', 'connect_timeout_ms' => 1000, 'request_timeout_ms' => 1000, 'max_attempts' => 2,
            'max_input_tokens' => 10000, 'max_output_tokens' => 128, 'max_cost_usd' => 1, 'config' => ['retry_statuses' => [0, 429, 500, 503]]]);
        $project = Project::create(['user_id' => $user->id, 'external_id' => 'vision-project', 'name' => 'Fixture']);
        $definition = WorkflowDefinition::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => 'Vision fixture', 'version' => 1, 'status' => 'active', 'definition' => ['schema_version' => 2, 'steps' => [['key' => 'vision', 'type' => 'image.vision']]]]);
        $this->root = WorkflowRun::create(['user_id' => $user->id, 'project_id' => $project->id, 'public_id' => (string) Str::uuid(), 'workflow_definition_id' => $definition->id, 'status' => 'running', 'sandbox' => false]);
        $artifact = (string) Str::uuid();
        $params = ['artifact_id' => $artifact, 'instruction' => 'Describe the image.', 'output_format' => 'text', 'inference' => 'external', 'max_output_chars' => 2000];
        $this->step = WorkflowStep::create(['workflow_run_id' => $this->root->id, 'user_id' => $user->id, 'step_key' => 'vision', 'type' => 'image.vision', 'status' => 'running', 'execution_id' => (string) Str::uuid(), 'payload' => $params]);
        $payload = ['task_key' => 'image.vision', 'task_version' => 1, 'params' => $params];
        $this->job = DeviceJob::create(['user_id' => $user->id, 'device_id' => $device->id, 'public_id' => (string) Str::uuid(), 'workflow_execution_id' => $this->step->execution_id,
            'tool_profile' => 'workflow.task', 'status' => 'running', 'payload' => $payload, 'payload_hash' => app(AuditLogger::class)->hash($payload), 'expires_at' => now()->addMinutes(5)]);
        $this->job->update(['signature' => app(DeviceJobSigner::class)->sign($this->job)]);
        $this->step->update(['external_run_type' => 'device_job', 'external_run_id' => $this->job->public_id]);
        $png = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($png);
        $bytes = ob_get_clean();
        imagedestroy($png);
        $body = ['schema_version' => 1, 'client_id' => $device->device_id, 'project_id' => $project->external_id, 'workflow_id' => $this->root->public_id,
            'workflow_execution_id' => $this->step->execution_id, 'instruction' => $params['instruction'], 'output_format' => 'text', 'max_output_chars' => 2000,
            'policy_revision' => app(WorkflowVisionPolicy::class)->capabilities()['revision'],
            'image' => ['artifact_id' => $artifact, 'mime' => 'image/png', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'width' => 1, 'height' => 1, 'base64' => base64_encode($bytes)]];

        return $this->approve($body);
    }

    private function approve(array $body): array
    {
        unset($body['approval']);
        $body['approval'] = ['hash' => WorkflowVisionService::approvalHash($body), 'token' => (string) Str::uuid(), 'expires_at' => now()->addSeconds(90)->toISOString()];

        return $body;
    }

    private function taskParams(array $params): void
    {
        $this->step->update(['payload' => $params]);
        $payload = [...$this->job->payload, 'params' => $params];
        $this->job->update(['payload' => $payload, 'payload_hash' => app(AuditLogger::class)->hash($payload)]);
        $this->job->update(['signature' => app(DeviceJobSigner::class)->sign($this->job)]);
    }

    private function transport(callable $assert, mixed $reply): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $expectation = $http->shouldReceive('request')->once()->withArgs($assert);
        $reply instanceof \Throwable ? $expectation->andThrow($reply) : $expectation->andReturn($reply);
        $factory = Mockery::mock(ProviderHttpClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($http);
        $this->app->instance(ProviderHttpClientFactory::class, $factory);
    }

    private function response(string $text = 'A dark pixel.'): Response
    {
        return new Response(200, [], json_encode(['id' => 'provider-id', 'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $text, 'reasoning' => 'DO NOT EXPOSE']]],
            'usage' => ['prompt_tokens' => 1100, 'completion_tokens' => 8, 'cost' => 0.001, 'private_blob' => 'DO NOT EXPOSE']], JSON_THROW_ON_ERROR));
    }

    public function test_valid_png_reaches_one_provider_with_conservative_separate_budget_and_safe_telemetry(): void
    {
        $body = $this->fixture();
        $this->getJson('/api/v1/proxy/vision/capabilities')->assertOk()->assertJsonPath('data.ready', true)->assertJsonPath('data.adapter', 'openai_chat_completions');
        $this->transport(function ($method, $url, $options) use ($body): bool {
            $this->assertSame('POST', $method);
            $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $url);
            $this->assertSame('data:image/png;base64,'.$body['image']['base64'], $options['json']['messages'][1]['content'][1]['image_url']['url']);
            $this->assertSame('fixture/vision', $options['json']['model']);
            $this->assertArrayNotHasKey('approval', $options['json']);
            $this->assertStringNotContainsString(str_repeat('x', 1024), json_encode($options['json']));

            return true;
        }, $this->response());
        $result = $this->postJson('/api/v1/proxy/vision', $body)->assertOk()->assertJsonPath('data.text', 'A dark pixel.')
            ->assertJsonPath('data.usage.input_tokens', 1100)->assertJsonPath('data.thinking_application', 'provider_not_confirmed');
        $this->assertStringNotContainsString('DO NOT EXPOSE', $result->getContent());
        $this->assertStringNotContainsString($body['image']['base64'], json_encode(LlmRun::all()));
        $this->assertStringNotContainsString('DO NOT EXPOSE', json_encode(LlmAttempt::all()));
        $this->assertGreaterThan(0.001024, LlmRun::sole()->estimated_cost_usd);
        $this->assertSame(1, LlmAttempt::count());
        $this->postJson('/api/v1/proxy/vision', $body)->assertStatus(409);
        $this->postJson('/api/v1/proxy/vision', $this->approve($body))->assertStatus(409);
        $this->assertSame(1, LlmAttempt::count());
    }

    public function test_missing_contract_and_incompatible_driver_are_not_vision_capabilities(): void
    {
        $this->fixture();
        $original = app(WorkflowVisionPolicy::class)->capabilities()['revision'];
        $this->profile->update(['meta' => []]);
        $this->getJson('/api/v1/proxy/vision/capabilities')->assertOk()->assertJsonPath('data.ready', false);
        $this->assertNotSame($original, app(WorkflowVisionPolicy::class)->capabilities()['revision']);
        $this->profile->credential->update(['provider' => 'openai', 'request_format' => 'responses']);
        $this->getJson('/api/v1/proxy/vision/capabilities')->assertOk()->assertJsonPath('data.ready', false);
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_bad_image_hash_dimensions_extra_fields_and_wrong_approval_never_dispatch(): void
    {
        $body = $this->fixture();
        foreach (['sha256' => str_repeat('a', 64), 'width' => 2, 'bytes' => 1, 'base64' => 'not-base64'] as $key => $value) {
            $changed = $body;
            $changed['image'][$key] = $value;
            $this->postJson('/api/v1/proxy/vision', $this->approve($changed))->assertStatus(422);
        }
        $this->postJson('/api/v1/proxy/vision', $body + ['model' => 'evil'])->assertStatus(422);
        $body['approval']['hash'] = str_repeat('a', 64);
        $this->postJson('/api/v1/proxy/vision', $body)->assertStatus(409);
        $this->assertSame(0, LlmAttempt::count());
        $this->assertSame(0, WorkflowOperation::count());
    }

    public function test_foreign_root_device_and_cancellation_cannot_reuse_a_running_step(): void
    {
        $body = $this->fixture();
        $other = $this->root->replicate(['public_id']);
        $other->public_id = (string) Str::uuid();
        $other->save();
        $changed = $body;
        $changed['workflow_id'] = $other->public_id;
        $this->postJson('/api/v1/proxy/vision', $this->approve($changed))->assertStatus(409);
        $changed['client_id'] = 'different-device';
        $this->postJson('/api/v1/proxy/vision', $this->approve($changed))->assertStatus(403);
        $this->job->update(['cancel_requested_at' => now()]);
        $this->postJson('/api/v1/proxy/vision', $body)->assertStatus(409);
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_policy_rotation_and_expiry_invalidate_approval(): void
    {
        $body = $this->fixture();
        NetworkPolicy::where('key', 'vision-test')->update(['max_cost_usd' => 0.9]);
        $this->postJson('/api/v1/proxy/vision', $body)->assertStatus(409);
        $body['policy_revision'] = app(WorkflowVisionPolicy::class)->capabilities()['revision'];
        $body = $this->approve($body);
        $body['approval']['expires_at'] = now()->subSecond()->toISOString();
        $this->postJson('/api/v1/proxy/vision', $body)->assertStatus(409);
        $this->assertSame(0, WorkflowOperation::count());
    }

    public function test_unknown_transport_result_is_consumed_and_not_blindly_retried(): void
    {
        $body = $this->fixture();
        $this->transport(fn (): bool => true, new ConnectException('private transport detail', new Request('POST', 'https://example.invalid')));
        $this->postJson('/api/v1/proxy/vision', $body)->assertStatus(503);
        $this->postJson('/api/v1/proxy/vision', $this->approve($body))->assertStatus(409);
        $this->assertSame(1, LlmAttempt::count());
    }

    public function test_public_output_limit_and_private_reasoning_markers_are_rejected(): void
    {
        $body = $this->fixture();
        $this->transport(fn (): bool => true, $this->response('<think>private thoughts</think>Answer'));
        $response = $this->postJson('/api/v1/proxy/vision', $body)->assertStatus(502);
        $this->assertStringNotContainsString('private thoughts', $response->getContent());
        $this->assertFalse(LlmRun::sole()->success);
    }

    public function test_task_output_and_explicit_external_choice_cannot_be_expanded_by_the_device(): void
    {
        $body = $this->fixture();
        foreach (['instruction' => 'A different task', 'output_format' => 'json', 'max_output_chars' => 3000] as $field => $value) {
            $this->postJson('/api/v1/proxy/vision', $this->approve([...$body, $field => $value]))->assertStatus(409);
        }
        $params = $this->step->payload;
        foreach ([null, 'local'] as $inference) {
            $this->taskParams([...$params, 'inference' => $inference]);
            $this->postJson('/api/v1/proxy/vision', $body)->assertStatus(409);
        }
        $this->taskParams($params);
        $this->job->update(['signature' => base64_encode('invalid signature')]);
        $this->postJson('/api/v1/proxy/vision', $body)->assertStatus(409);
        $this->assertSame(0, WorkflowOperation::count());
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_nested_root_bound_task_preserves_approved_unicode_whitespace_and_json_object_shape(): void
    {
        $body = $this->fixture();
        $child = $this->root->replicate(['public_id']);
        $child->fill(['public_id' => (string) Str::uuid(), 'root_workflow_run_id' => $this->root->id, 'parent_workflow_run_id' => $this->root->id])->save();
        $this->step->update(['workflow_run_id' => $child->id]);
        $body['instruction'] = "  Describe / Grüße\u{2028}this image.  ";
        $body['output_format'] = 'json';
        $this->taskParams([...$this->step->payload, 'instruction' => $body['instruction'], 'output_format' => 'json']);
        $this->transport(function ($method, $url, $options) use ($body): bool {
            $this->assertSame($body['instruction'], $options['json']['messages'][1]['content'][0]['text']);
            $this->assertSame(['type' => 'json_object'], $options['json']['response_format']);

            return true;
        }, $this->response('{}'));
        $response = $this->postJson('/api/v1/proxy/vision', $this->approve($body))->assertOk();
        $this->assertStringContainsString('"data":{}', $response->getContent());
    }

    public function test_price_credential_rotation_and_capability_output_bounds_are_bound_to_revision(): void
    {
        $this->fixture();
        $policy = app(WorkflowVisionPolicy::class);
        $revision = $policy->capabilities()['revision'];
        ProviderPriceSnapshot::query()->update(['input_per_million' => 1.1]);
        $this->assertNotSame($revision, $revision = $policy->capabilities()['revision']);
        // A same-second secret rotation also changes the opaque revision, without exposing the credential.
        $credential = $this->profile->credential;
        $credential->timestamps = false;
        $credential->update(['api_key' => 'rotated-fixture-secret']);
        $this->assertNotSame($revision, $policy->capabilities()['revision']);
        Config::set('luczor.proxy.max_output_tokens', 196608);
        $this->profile->update(['max_tokens' => 196608, 'context_window' => 500000]);
        NetworkPolicy::query()->update(['max_output_tokens' => 196608]);
        $this->assertSame(131072, $policy->capabilities()['max_output_tokens']);
        NetworkPolicy::query()->update(['request_timeout_ms' => 600000]);
        $this->assertSame(600000, $policy->capabilities()['request_timeout_ms']);
        NetworkPolicy::query()->update(['request_timeout_ms' => 600001]);
        $this->assertFalse($policy->capabilities()['ready']);
        $this->assertSame(0, $policy->capabilities()['request_timeout_ms']);
        NetworkPolicy::query()->update(['request_timeout_ms' => 1000]);
        ProviderPriceSnapshot::query()->delete();
        $this->assertFalse($policy->capabilities()['ready']);
    }

    public function test_vision_uses_the_existing_configured_proxy_rate_bucket(): void
    {
        $body = $this->fixture();
        Config::set('luczor.proxy.requests_per_minute', 1);
        $invalid = $body;
        $invalid['approval']['hash'] = str_repeat('a', 64);
        $this->postJson('/api/v1/proxy/vision', $invalid)->assertStatus(409);
        $this->postJson('/api/v1/proxy/vision', $body)->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(0, LlmAttempt::count());
    }

    public function test_provider_answer_larger_than_the_approved_task_limit_is_not_returned(): void
    {
        $body = $this->fixture();
        $this->transport(fn (): bool => true, $this->response(str_repeat('a', 2001)));
        $this->postJson('/api/v1/proxy/vision', $body)->assertStatus(502);
        $this->assertFalse(LlmRun::sole()->success);
        $this->postJson('/api/v1/proxy/vision', $this->approve($body))->assertStatus(409);
    }

    public function test_ranked_and_experimental_route_metadata_invalidate_the_policy_revision(): void
    {
        $this->fixture();
        $policy = app(WorkflowVisionPolicy::class);
        ModelUseCase::query()->update(['routing_strategy' => 'ranked']);
        $revision = $policy->capabilities()['revision'];
        ModelRanking::create(['task_type' => WorkflowVisionPolicy::TASK, 'model_id' => $this->profile->model_id,
            'model_profile_id' => $this->profile->id, 'provider_id' => 'openrouter', 'sample_count' => 5, 'score' => 0.9]);
        $this->assertNotSame($revision, $policy->capabilities()['revision']);
        ModelUseCase::query()->update(['routing_strategy' => 'experiment']);
        $experiment = LlmExperiment::create(['key' => 'vision-fixture', 'name' => 'Fixture', 'task_type' => 'vision', 'status' => 'active',
            'traffic_percent' => 100, 'variants' => [['model_id' => $this->profile->model_id, 'weight' => 1]]]);
        $revision = $policy->capabilities()['revision'];
        $this->assertSame($revision, $policy->capabilities()['revision']);
        $experiment->update(['traffic_percent' => 50]);
        $this->assertNotSame($revision, $policy->capabilities()['revision']);
    }
}
