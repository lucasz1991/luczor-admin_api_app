<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function definition(array $steps): WorkflowDefinition
    {
        $user = User::factory()->create();

        return WorkflowDefinition::create([
            'user_id' => $user->id,
            'name' => 'wf-'.uniqid(),
            'version' => 1,
            'status' => 'active',
            'definition' => ['steps' => $steps],
        ]);
    }

    /** advance() sets manual steps to 'ready' but never auto-dispatches them. */
    private function ready(WorkflowService $svc, $run, string $key)
    {
        $svc->advance($run->fresh());

        return $run->steps()->where('step_key', $key)->first();
    }

    public function test_success_route_to_end_short_circuits_the_run(): void
    {
        $svc = new WorkflowService;
        $def = $this->definition([
            ['key' => 'a', 'type' => 'manual', 'routes' => ['success' => ['type' => 'end']]],
            ['key' => 'b', 'type' => 'manual', 'depends_on' => ['a']],
        ]);
        $run = $svc->createRun($def);
        $a = $this->ready($svc, $run, 'a');

        $svc->complete($a, ['outcome' => 'success']);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('queued', $run->steps()->where('step_key', 'b')->first()->status);
    }

    public function test_failed_route_branches_to_a_cleanup_step_instead_of_failing_the_run(): void
    {
        $svc = new WorkflowService;
        $def = $this->definition([
            ['key' => 'a', 'type' => 'manual', 'max_attempts' => 1, 'routes' => ['failed' => ['type' => 'step', 'step_key' => 'cleanup']]],
            ['key' => 'cleanup', 'type' => 'manual'],
        ]);
        $run = $svc->createRun($def);
        $a = $this->ready($svc, $run, 'a');

        $svc->fail($a, 'boom');

        $this->assertNotSame('failed', $run->fresh()->status);
        $this->assertSame('ready', $run->steps()->where('step_key', 'cleanup')->first()->status);
    }

    public function test_self_loop_is_bounded_by_max_iterations(): void
    {
        $svc = new WorkflowService;
        $def = $this->definition([
            ['key' => 'loop', 'type' => 'manual', 'routes' => ['success' => ['type' => 'step', 'step_key' => 'loop', 'max_iterations' => 1]]],
        ]);
        $run = $svc->createRun($def);

        // First jump is allowed (counter 1 <= 1)...
        $svc->complete($this->ready($svc, $run, 'loop'), ['outcome' => 'success']);
        $this->assertSame('running', $run->fresh()->status);

        // ...the second jump exceeds the limit and fails the run.
        $svc->complete($this->ready($svc, $run, 'loop'), ['outcome' => 'success']);
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('loop_limit_exceeded', $run->output['_route_error'] ?? '');
    }

    public function test_route_to_unknown_step_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        (new WorkflowService)->assertDefinition([
            'steps' => [['key' => 'a', 'type' => 'manual', 'routes' => ['success' => ['type' => 'step', 'step_key' => 'ghost']]]],
        ]);
    }
}
