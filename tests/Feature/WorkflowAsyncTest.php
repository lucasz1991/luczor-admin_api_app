<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowAsyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(WorkflowService $svc, array $steps)
    {
        $def = WorkflowDefinition::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'wf-'.uniqid(), 'version' => 1, 'status' => 'active',
            'definition' => ['steps' => $steps],
        ]);
        $run = $svc->createRun($def);
        $svc->advance($run->fresh());

        return $run->fresh();
    }

    public function test_step_and_run_duration_are_recorded_on_completion(): void
    {
        $svc = new WorkflowService;
        $run = $this->makeRun($svc, [['key' => 'a', 'type' => 'manual']]);
        $step = $run->steps()->first();
        $step->update(['started_at' => now()->subSeconds(2)]);

        $svc->complete($step->fresh(), ['outcome' => 'success']);

        $this->assertGreaterThanOrEqual(1000, $step->fresh()->duration_ms);
        $this->assertNotNull($run->fresh()->duration_ms);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_expire_fails_a_step_stuck_past_its_timeout(): void
    {
        $svc = new WorkflowService;
        $run = $this->makeRun($svc, [['key' => 'a', 'type' => 'manual', 'max_attempts' => 1]]);
        // Simulate a step that started but never reported back (default timeout 300s).
        $run->steps()->first()->update(['status' => 'running', 'started_at' => now()->subSeconds(400)]);

        $expired = $svc->expireTimedOutSteps($run->fresh());

        $this->assertSame(1, $expired);
        $this->assertSame('failed', $run->steps()->first()->fresh()->status);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_record_artifact_masks_secrets(): void
    {
        $svc = new WorkflowService;
        $run = $this->makeRun($svc, [['key' => 'a', 'type' => 'manual']]);
        $step = $run->steps()->first();

        $artifact = $svc->recordArtifact($step, [
            'artifact_type' => 'json',
            'label' => 'result',
            'metadata' => [
                'password' => 'hunter2',
                'url' => 'http://example.test',
                'nested' => ['api_key' => 'abc', 'ok' => 1],
            ],
        ]);

        $this->assertSame($step->id, $artifact->workflow_step_id);
        $this->assertSame('***', $artifact->metadata['password']);
        $this->assertSame('***', $artifact->metadata['nested']['api_key']);
        $this->assertSame('http://example.test', $artifact->metadata['url']);
        $this->assertSame(1, $artifact->metadata['nested']['ok']);
        $this->assertCount(1, $run->fresh()->artifacts);
    }
}
