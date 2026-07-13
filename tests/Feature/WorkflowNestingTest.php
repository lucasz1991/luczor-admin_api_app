<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowNestingTest extends TestCase
{
    use RefreshDatabase;

    private function definition(int $userId, array $steps): WorkflowDefinition
    {
        return WorkflowDefinition::create([
            'user_id' => $userId,
            'name' => 'wf-'.uniqid(), 'version' => 1, 'status' => 'active',
            'definition' => ['steps' => $steps],
        ]);
    }

    public function test_parent_completes_when_the_child_workflow_finishes(): void
    {
        $svc = new WorkflowService;
        $userId = User::factory()->create()->id;
        $child = $this->definition($userId, [['key' => 'c', 'type' => 'manual']]);
        $parent = $this->definition($userId, [
            ['key' => 'wf', 'type' => 'workflow', 'payload' => ['workflow_definition_id' => $child->id]],
        ]);

        $parentRun = $svc->createRun($parent);
        $parentStep = $parentRun->steps()->where('type', 'workflow')->first();

        $childRun = $svc->startChildWorkflow($parentStep);
        $this->assertSame($parentStep->id, $childRun->parent_workflow_step_id);
        $this->assertSame('running', $parentStep->fresh()->status);

        // Finish the child's only step; then settle the parent (idempotent).
        $svc->complete($childRun->steps()->first()->fresh(), ['outcome' => 'success']);
        $svc->syncChildWorkflows($parentRun->fresh());

        $this->assertSame('completed', $childRun->fresh()->status);
        $this->assertSame('completed', $parentStep->fresh()->status);
        $this->assertSame('completed', $parentRun->fresh()->status);
    }

    public function test_nested_cycle_is_rejected(): void
    {
        $svc = new WorkflowService;
        $userId = User::factory()->create()->id;
        $a = $this->definition($userId, [['key' => 'x', 'type' => 'manual']]);
        $b = $this->definition($userId, [['key' => 'y', 'type' => 'workflow', 'payload' => ['workflow_definition_id' => $a->id]]]);
        // Close the loop: a now embeds b as well (the saved hook re-syncs the graph).
        $a->update(['definition' => ['steps' => [['key' => 'x', 'type' => 'workflow', 'payload' => ['workflow_definition_id' => $b->id]]]]]);

        $this->assertTrue($b->fresh()->includesDefinition($a->id));
        $this->assertTrue($a->fresh()->includesDefinition($b->id));

        $runA = $svc->createRun($a->fresh());
        $this->expectException(HttpException::class);
        $svc->startChildWorkflow($runA->steps()->where('type', 'workflow')->first());
    }

    public function test_embedded_definition_is_edit_locked(): void
    {
        $userId = User::factory()->create()->id;
        $child = $this->definition($userId, [['key' => 'c', 'type' => 'manual']]);
        $parent = $this->definition($userId, [['key' => 'wf', 'type' => 'workflow', 'payload' => ['workflow_definition_id' => $child->id]]]);

        $this->assertTrue($child->fresh()->is_included);
        $this->assertTrue($child->fresh()->is_edit_locked);
        $this->assertFalse($parent->fresh()->is_included);
    }

    public function test_workflow_step_requires_a_child_definition_id(): void
    {
        $this->expectException(HttpException::class);
        (new WorkflowService)->assertDefinition([
            'steps' => [['key' => 'wf', 'type' => 'workflow']],
        ]);
    }
}
