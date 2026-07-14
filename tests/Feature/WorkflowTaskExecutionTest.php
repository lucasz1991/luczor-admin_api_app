<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\DeviceJob;
use App\Models\MemoryLink;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/** SOLL §14 P15b — executor branches for wait/memory/task steps and client-task device bundles. */
class WorkflowTaskExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        Config::set('luczor.device_jobs.private_key', $privateKey);
    }

    /** @param array<int,array<string,mixed>> $steps */
    private function startRun(User $user, array $steps): WorkflowRun
    {
        $definition = WorkflowDefinition::create([
            'user_id' => $user->id, 'name' => 'Exec', 'version' => 1, 'status' => 'active',
            'definition' => ['steps' => $steps],
        ]);
        $svc = app(WorkflowService::class);

        return $svc->advance($svc->createRun($definition));
    }

    public function test_memory_remember_step_writes_a_memory_link(): void
    {
        $user = User::factory()->create();
        $run = $this->startRun($user, [
            ['key' => 'm', 'type' => 'memory.remember', 'payload' => ['content' => 'Kunde bevorzugt Rechnungen als PDF.']],
        ]);

        $step = $run->steps()->first();
        $this->assertSame('completed', $step->status);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNotEmpty($step->output['memory_link_id'] ?? null);
        $this->assertDatabaseHas('memory_links', [
            'user_id' => $user->id,
            'type' => 'workflow',
            'summary' => 'Kunde bevorzugt Rechnungen als PDF.',
        ]);
    }

    public function test_memory_recall_step_fills_output_and_run_context(): void
    {
        $user = User::factory()->create();
        MemoryLink::create([
            'user_id' => $user->id, 'scope' => 'project',
            'dataset' => "user:{$user->id}:projects:default",
            'type' => 'note', 'visibility' => 'syncable', 'staleness' => 'fresh',
            'importance' => 0.9, 'summary' => 'Fokus liegt auf dem Rechnungs-Workflow.',
        ]);

        $run = $this->startRun($user, [
            ['key' => 'r', 'type' => 'memory.recall', 'payload' => ['query' => 'Rechnungs', 'top_k' => 3]],
        ]);

        $step = $run->steps()->first();
        $this->assertSame('completed', $step->status);
        $this->assertSame(1, $step->output['count']);
        $this->assertStringContainsString('Rechnungs-Workflow', $step->output['memories'][0]['content']);
        $this->assertArrayHasKey('r', $run->fresh()->context ?? []);
    }

    public function test_task_create_step_creates_an_open_user_task(): void
    {
        $user = User::factory()->create();
        $run = $this->startRun($user, [
            ['key' => 't', 'type' => 'task.create', 'payload' => ['title' => 'Angebot nachfassen', 'priority' => 'high']],
        ]);

        $this->assertSame('completed', $run->fresh()->status);
        $task = Task::first();
        $this->assertSame('Angebot nachfassen', $task->title);
        $this->assertSame('open', $task->status);
        $this->assertSame('high', $task->priority);
        $this->assertSame('workflow', $task->client_id);
    }

    public function test_wait_step_parks_and_completes_after_the_delay(): void
    {
        $user = User::factory()->create();
        $run = $this->startRun($user, [
            ['key' => 'w', 'type' => 'wait.seconds', 'payload' => ['seconds' => 30]],
        ]);

        $step = $run->steps()->first();
        $this->assertSame('running', $step->status);
        $this->assertSame('running', $run->fresh()->status);

        $svc = app(WorkflowService::class);
        $this->assertSame(0, $svc->settleWaitSteps($run->fresh()));   // noch nicht abgelaufen

        $this->travel(31)->seconds();
        $this->assertSame(1, $svc->settleWaitSteps($run->fresh()));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(30, $run->steps()->first()->output['waited_seconds']);
        $this->travelBack();
    }

    public function test_client_task_runs_as_device_job_and_result_flows_back(): void
    {
        $user = User::factory()->create();
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Device', 'abilities' => ['device.connect'], 'active' => true]);
        $this->withHeader('X-Api-Key', $minted['plain'])->postJson('/api/v1/devices/register', [
            'client_id' => 'desktop-1', 'name' => 'Desktop 1',
        ])->assertCreated();

        $run = $this->startRun($user, [
            ['key' => 'open', 'type' => 'browser.open_url', 'payload' => ['url' => 'https://example.org']],
        ]);

        // Step wartet als device_job-Bundle auf das Gerät.
        $step = $run->steps()->first();
        $this->assertSame('running', $step->status);
        $this->assertSame('device_job', $step->external_run_type);
        $job = DeviceJob::where('public_id', $step->external_run_id)->firstOrFail();
        $this->assertSame('workflow.task', $job->tool_profile);
        $this->assertSame('browser.open_url', $job->payload['task_key']);
        $this->assertSame('https://example.org', $job->payload['params']['url']);
        $this->assertSame($run->public_id, $job->payload['workflow']['run']);
        $this->assertSame('approval_required', $job->status);   // kein Unattended-Policy-Match

        // Gerät: freigeben → starten → Ergebnis melden (echte API-Endpunkte).
        $device = fn (string $path, array $body) => $this->withHeader('X-Api-Key', $minted['plain'])
            ->postJson('/api/v1/devices/jobs/'.$job->public_id.$path, array_merge(['client_id' => 'desktop-1'], $body));
        $device('/approve', ['approved' => true])->assertOk();
        $device('/start', [])->assertOk();
        $device('/complete', ['ok' => true, 'result' => ['opened' => true]])->assertOk();

        // Auf dem sync-Treiber settelt der Poke aus completeJob den Step inline.
        $step = $run->steps()->first()->fresh();
        $this->assertSame('completed', $step->status);
        $this->assertTrue($step->output['result']['opened']);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_rejected_device_job_fails_the_step(): void
    {
        $user = User::factory()->create();
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Device', 'abilities' => ['device.connect'], 'active' => true]);
        $this->withHeader('X-Api-Key', $minted['plain'])->postJson('/api/v1/devices/register', [
            'client_id' => 'desktop-1', 'name' => 'Desktop 1',
        ])->assertCreated();

        $run = $this->startRun($user, [
            ['key' => 'open', 'type' => 'browser.open_url', 'payload' => ['url' => 'https://example.org'], 'max_attempts' => 1],
        ]);
        $job = DeviceJob::firstOrFail();

        $this->withHeader('X-Api-Key', $minted['plain'])->postJson('/api/v1/devices/jobs/'.$job->public_id.'/approve', [
            'client_id' => 'desktop-1', 'approved' => false, 'reason' => 'Nicht jetzt',
        ])->assertOk();

        $step = $run->steps()->first()->fresh();
        $this->assertSame('failed', $step->status);
        $this->assertStringContainsString('device_job_rejected', $step->error);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_client_task_without_a_device_fails_cleanly(): void
    {
        $user = User::factory()->create();
        $run = $this->startRun($user, [
            ['key' => 'open', 'type' => 'browser.open_url', 'payload' => ['url' => 'https://example.org'], 'max_attempts' => 1],
        ]);

        $step = $run->steps()->first();
        $this->assertSame('failed', $step->status);
        $this->assertStringContainsString('No device is available', $step->error);
    }

    public function test_catalog_approval_floor_forces_a_gate_on_mutating_device_tasks(): void
    {
        $steps = app(WorkflowService::class)->assertDefinition([
            'steps' => [['key' => 'w', 'type' => 'file.write', 'payload' => ['path' => 'a.txt', 'content' => 'x']]],
        ]);

        $this->assertTrue($steps[0]['requires_approval']);
    }
}
