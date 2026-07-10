<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\DeviceJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DeviceJobsAndWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        Config::set('luczor.device_jobs.private_key', $privateKey);
    }

    public function test_signed_device_job_requires_local_approval_and_is_audited(): void
    {
        [$user, $token] = $this->token(['device.connect', 'device.jobs.read', 'device.jobs.write']);
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/devices/register', [
            'client_id' => 'desktop-1', 'name' => 'Desktop 1',
        ])->assertCreated();

        $job = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/device-jobs', [
            'device_id' => 'desktop-1',
            'tool_profile' => 'desktop.input.type_text',
            'payload' => ['text' => 'Hello'],
        ])->assertCreated()->json('data');

        $this->assertSame('approval_required', $job['status']);
        $this->assertNotEmpty($job['signature']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'device_job.created', 'actor_user_id' => $user->id]);

        $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/devices/jobs/next?client_id=desktop-1')
            ->assertOk()
            ->assertJsonPath('data.id', $job['public_id'])
            ->assertJsonPath('data.payload.text', 'Hello');

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/devices/jobs/'.$job['public_id'].'/approve', [
            'client_id' => 'desktop-1', 'approved' => true,
        ])->assertOk()->assertJsonPath('data.status', 'queued');
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/devices/jobs/'.$job['public_id'].'/start', [
            'client_id' => 'desktop-1',
        ])->assertOk()->assertJsonPath('data.status', 'running');
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/devices/jobs/'.$job['public_id'].'/complete', [
            'client_id' => 'desktop-1', 'ok' => true, 'result' => ['typed' => 5],
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(5, AuditEvent::count());
        $this->assertNotNull(DeviceJob::first()->result_hash);
    }

    public function test_second_user_cannot_read_or_approve_another_users_device_job(): void
    {
        [, $firstToken] = $this->token(['device.connect', 'device.jobs.write']);
        [, $secondToken] = $this->token(['device.connect', 'device.jobs.read']);
        $this->withHeader('X-Api-Key', $firstToken)->postJson('/api/v1/devices/register', [
            'client_id' => 'first-device', 'name' => 'First',
        ])->assertCreated();
        $jobId = $this->withHeader('X-Api-Key', $firstToken)->postJson('/api/v1/device-jobs', [
            'device_id' => 'first-device', 'tool_profile' => 'desktop.windows.list',
        ])->assertCreated()->json('data.public_id');

        $this->withHeader('X-Api-Key', $secondToken)->getJson('/api/v1/devices/jobs/next?client_id=first-device')->assertNotFound();
        $this->withHeader('X-Api-Key', $secondToken)->postJson('/api/v1/devices/jobs/'.$jobId.'/approve', [
            'client_id' => 'first-device', 'approved' => true,
        ])->assertNotFound();
    }

    public function test_workflow_dependencies_retries_and_cancel_are_persistent(): void
    {
        [, $token] = $this->token(['brain.read', 'brain.write']);
        $definitionId = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflows', [
            'name' => 'Build flow',
            'definition' => ['steps' => [
                ['key' => 'lint', 'type' => 'manual', 'max_attempts' => 2],
                ['key' => 'review', 'type' => 'approval', 'depends_on' => ['lint']],
                ['key' => 'publish', 'type' => 'manual', 'depends_on' => ['review']],
            ]],
        ])->assertCreated()->json('data.id');

        $run = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflows/'.$definitionId.'/runs', [])
            ->assertCreated()->json('data');
        $steps = collect($run['steps'])->keyBy('step_key');
        $this->assertSame('ready', $steps['lint']['status']);
        $this->assertSame('queued', $steps['review']['status']);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflow-steps/'.$steps['lint']['id'].'/fail', ['error' => 'lint failed'])
            ->assertOk()->assertJsonPath('data.steps.0.status', 'queued');
        $this->travel(3)->seconds();
        $afterRetry = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflow-runs/'.$run['id'].'/advance', [])
            ->assertOk()->json('data');
        $retryStep = collect($afterRetry['steps'])->firstWhere('step_key', 'lint');
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflow-steps/'.$retryStep['id'].'/complete', ['output' => ['passed' => true]])
            ->assertOk();

        $ready = $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/workflow-runs/'.$run['id'])->assertOk()->json('data');
        $review = collect($ready['steps'])->firstWhere('step_key', 'review');
        $this->assertSame('awaiting_approval', $review['status']);
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflow-runs/'.$run['id'].'/cancel', [])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_safe_context_workflow_step_is_queued_and_completed_automatically(): void
    {
        [$user, $token] = $this->token(['brain.read', 'brain.write']);
        $project = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/projects', [
            'external_id' => 'workflow-context-project', 'name' => 'Workflow context project',
        ])->assertCreated()->json('data');
        $definitionId = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflows', [
            'project_id' => $project['external_id'],
            'name' => 'Context flow',
            'definition' => ['steps' => [[
                'key' => 'context', 'type' => 'context',
                'payload' => ['query' => 'Pruefe Kontext', 'task_type' => 'coding.context'],
            ]]],
        ])->assertCreated()->json('data.id');

        $runId = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflows/'.$definitionId.'/runs', [])
            ->assertCreated()->json('data.id');
        $run = $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/workflow-runs/'.$runId)
            ->assertOk()->json('data');
        $step = collect($run['steps'])->firstWhere('step_key', 'context');

        $this->assertSame($user->id, $run['user_id']);
        $this->assertSame('completed', $run['status']);
        $this->assertSame('completed', $step['status']);
        $this->assertNotEmpty($step['output']['context_id']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'workflow.step_completed']);
    }

    /** @return array{0: User, 1: string} */
    private function token(array $abilities): array
    {
        $user = User::factory()->create();
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Device', 'abilities' => $abilities, 'active' => true]);
        return [$user, $minted['plain']];
    }
}
