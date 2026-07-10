<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\ApiActor;
use App\Services\AuditLogger;
use App\Services\WorkflowService;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function storeDefinition(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:160'],
            'version' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'definition' => ['required', 'array'],
        ]);
        $project = $actor->project($request, $data['project_id'] ?? null);
        $definition = WorkflowDefinition::updateOrCreate(
            ['user_id' => $actor->userId($request), 'project_id' => $project?->id, 'name' => $data['name'], 'version' => $data['version'] ?? 1],
            ['status' => 'active', 'definition' => $data['definition']]
        );
        // Validates the graph before returning it, without starting work.
        app(WorkflowService::class)->assertDefinition($definition->definition);

        return response()->json(['data' => $definition], 201);
    }

    public function start(Request $request, WorkflowDefinition $workflowDefinition, ApiActor $actor, WorkflowService $workflows, AuditLogger $audit)
    {
        $actor->assertOwned($request, $workflowDefinition);
        $data = $request->validate([
            'input' => ['nullable', 'array'],
            'agent_run_id' => ['nullable', 'integer', 'exists:agent_runs,id'],
        ]);
        if (! empty($data['agent_run_id'])) {
            $actor->assertOwned($request, AgentRun::findOrFail($data['agent_run_id']));
        }
        $run = $workflows->advance($workflows->createRun($workflowDefinition, $data['input'] ?? [], $data['agent_run_id'] ?? null));
        $audit->record([
            'actor_user_id' => $actor->userId($request), 'project_id' => $run->project_id,
            'event_type' => 'workflow.started', 'outcome' => 'queued', 'payload' => ['workflow_run' => $run->public_id],
        ]);

        return response()->json(['data' => $run], 201);
    }

    public function show(Request $request, WorkflowRun $workflowRun, ApiActor $actor)
    {
        $actor->assertOwned($request, $workflowRun);
        return response()->json(['data' => $workflowRun->load(['steps', 'definition'])]);
    }

    public function advance(Request $request, WorkflowRun $workflowRun, ApiActor $actor, WorkflowService $workflows)
    {
        $actor->assertOwned($request, $workflowRun);
        return response()->json(['data' => $workflows->advance($workflowRun)]);
    }

    public function completeStep(Request $request, WorkflowStep $workflowStep, ApiActor $actor, WorkflowService $workflows)
    {
        $actor->assertOwned($request, $workflowStep->run);
        $data = $request->validate(['output' => ['nullable', 'array']]);
        return response()->json(['data' => $workflows->complete($workflowStep, $data['output'] ?? [])]);
    }

    public function failStep(Request $request, WorkflowStep $workflowStep, ApiActor $actor, WorkflowService $workflows)
    {
        $actor->assertOwned($request, $workflowStep->run);
        $data = $request->validate(['error' => ['required', 'string', 'max:8000']]);
        return response()->json(['data' => $workflows->fail($workflowStep, $data['error'])]);
    }

    public function cancel(Request $request, WorkflowRun $workflowRun, ApiActor $actor, WorkflowService $workflows)
    {
        $actor->assertOwned($request, $workflowRun);
        return response()->json(['data' => $workflows->cancel($workflowRun)]);
    }
}
