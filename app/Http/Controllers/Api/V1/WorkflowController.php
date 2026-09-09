<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowOperation;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\WorkflowAuthoringService;
use App\Services\WorkflowBindingTypes;
use App\Services\WorkflowBoundaryStop;
use App\Services\WorkflowService;
use App\Services\WorkflowTemplateService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class WorkflowController extends Controller
{
    public function index(Request $request, WorkflowAuthoringService $authoring)
    {
        $data = $request->validate(['project_id' => ['nullable', 'string', 'max:190']]);
        $query = WorkflowDefinition::where('user_id', $request->user()->id)->with('project');
        if (array_key_exists('project_id', $data)) {
            $query->where('project_id', $authoring->projectId((int) $request->user()->id, $data['project_id']));
        }

        return response()->json(['data' => $query->orderByDesc('updated_at')->limit(200)->get()->map(fn ($definition) => $authoring->serialize($definition))]);
    }

    public function definition(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring)
    {
        $authoring->assertOwned((int) $request->user()->id, $workflowDefinition);
        $data = $authoring->serialize($workflowDefinition, true);
        try {
            $data['expanded_snapshot'] = $authoring->snapshot($workflowDefinition, (int) $request->user()->id, $workflowDefinition->project_id, [], false);
        } catch (HttpExceptionInterface $error) {
            $data['expanded_snapshot'] = null;
            $data['composition_error'] = $error->getStatusCode() === 404 ? 'A nested workflow is unavailable.' : $error->getMessage();
        }

        return response()->json(['data' => $data]);
    }

    public function templates()
    {
        return response()->json(['data' => WorkflowTemplateService::templates()]);
    }

    public function revision(Request $request, WorkflowDefinition $workflowDefinition, int $version, WorkflowAuthoringService $authoring)
    {
        $authoring->assertOwned((int) $request->user()->id, $workflowDefinition);

        return response()->json(['data' => $workflowDefinition->revisions()->where('version', $version)->firstOrFail()]);
    }

    public function validateDefinition(Request $request, WorkflowAuthoringService $authoring)
    {
        $data = $request->validate(['definition' => ['required', 'array'], 'project_id' => ['nullable', 'string', 'max:190'], 'workflow_definition_id' => ['nullable', 'integer']]);
        if (! empty($data['workflow_definition_id'])) {
            $authoring->assertOwned((int) $request->user()->id, WorkflowDefinition::findOrFail($data['workflow_definition_id']));
        }
        $steps = $authoring->validate((int) $request->user()->id, $data['definition'], $authoring->projectId((int) $request->user()->id, $data['project_id'] ?? null), $data['workflow_definition_id'] ?? null);

        return response()->json(['data' => ['valid' => true, 'definition' => $data['definition'], 'steps' => $steps,
            'binding_validation' => app(WorkflowBindingTypes::class)->inspect($data['definition'], $steps)]]);
    }

    public function storeDefinition(Request $request, WorkflowAuthoringService $authoring)
    {
        $data = $request->validate($this->writeRules());

        return response()->json(['data' => $authoring->save((int) $request->user()->id, $data)], 201);
    }

    public function updateDefinition(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring)
    {
        $authoring->assertOwned((int) $request->user()->id, $workflowDefinition);
        $data = $request->validate($this->writeRules() + ['expected_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $authoring->save((int) $request->user()->id, $data, $workflowDefinition->id)]);
    }

    public function operation(Request $request, string $operationId)
    {
        $operation = WorkflowOperation::where('user_id', $request->user()->id)->where('operation_id', $operationId)->first();

        return response()->json(['data' => $operation
            ? ['operation_id' => $operationId, 'status' => $operation->status, 'response' => $operation->response]
            : ['operation_id' => $operationId, 'status' => 'not_found']]);
    }

    public function start(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring, WorkflowService $workflows)
    {
        $authoring->assertOwned((int) $request->user()->id, $workflowDefinition);
        $data = $request->validate([
            'operation_id' => ['nullable', 'uuid'], 'input' => ['nullable', 'array'], 'sandbox' => ['nullable', 'boolean'],
            'device_id' => ['nullable', 'string', 'max:120'], 'project_id' => ['nullable', 'string', 'max:190'],
            'agent_run_id' => ['nullable', 'integer'],
        ]);
        if (! empty($data['agent_run_id'])) {
            abort_unless(AgentRun::where('user_id', $request->user()->id)->whereKey($data['agent_run_id'])->exists(), 404);
        }
        $result = $authoring->operate((int) $request->user()->id, $data['operation_id'] ?? null, 'run.start', $data + ['definition_id' => $workflowDefinition->id], function () use ($workflowDefinition, $workflows, $data) {
            $context = ['strict_target' => isset($data['operation_id'])];
            foreach (['device_id', 'project_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $context[$field] = $data[$field];
                }
            }
            $run = $workflows->createRun($workflowDefinition, $data['input'] ?? [], $data['agent_run_id'] ?? null, $data['sandbox'] ?? false, $context);
            $workflows->advance($run);

            return $run->fresh(['steps'])->toArray();
        });

        return response()->json(['data' => $result], 201);
    }

    public function runs(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring)
    {
        $authoring->assertOwned((int) $request->user()->id, $workflowDefinition);

        return response()->json(['data' => $workflowDefinition->runs()->orderByDesc('id')->limit(50)->get()]);
    }

    public function show(Request $request, string $workflowRun)
    {
        $run = $this->ownedRun($request, $workflowRun);
        app(WorkflowService::class)->settleCancellation($run);

        $root = WorkflowRun::where('user_id', $request->user()->id)->findOrFail($run->root_workflow_run_id ?: $run->id);
        if (WorkflowBoundaryStop::requested($root)) {
            $root = app(WorkflowBoundaryStop::class)->settle($root);
        }

        return response()->json(['data' => array_merge($run->fresh(['steps', 'artifacts'])->toArray(), ['root_budget' => $root->only(['id', 'public_id', 'status', 'budgets', 'budget_state'])])]);
    }

    public function advance(Request $request, string $workflowRun, WorkflowService $workflows)
    {
        return response()->json(['data' => $workflows->advance($this->ownedRun($request, $workflowRun))]);
    }

    public function completeStep(Request $request, WorkflowStep $workflowStep, WorkflowService $workflows)
    {
        abort_unless((int) $workflowStep->user_id === (int) $request->user()->id, 404);
        abort_unless(in_array($workflowStep->type, ['manual', 'approval', 'device_job'], true), 409, 'Executed steps require a verified executor result.');
        $data = $request->validate(['output' => ['nullable', 'array']]);

        return response()->json(['data' => $workflows->complete($workflowStep, $data['output'] ?? [])]);
    }

    public function approveStep(Request $request, WorkflowStep $workflowStep, WorkflowService $workflows)
    {
        return response()->json(['data' => $workflows->approve($workflowStep, (int) $request->user()->id)]);
    }

    public function failStep(Request $request, WorkflowStep $workflowStep, WorkflowService $workflows)
    {
        abort_unless((int) $workflowStep->user_id === (int) $request->user()->id, 404);
        $data = $request->validate(['error' => ['required', 'string', 'max:8000']]);

        return response()->json(['data' => $workflows->fail($workflowStep, $data['error'])]);
    }

    public function cancel(Request $request, string $workflowRun, WorkflowService $workflows)
    {
        return response()->json(['data' => $workflows->cancel($this->ownedRun($request, $workflowRun))]);
    }

    private function ownedRun(Request $request, string $id): WorkflowRun
    {
        return WorkflowRun::where('user_id', $request->user()->id)->where(ctype_digit($id) ? 'id' : 'public_id', $id)->firstOrFail();
    }

    private function writeRules(): array
    {
        return ['operation_id' => ['nullable', 'uuid'], 'project_id' => ['nullable', 'string', 'max:190'], 'name' => ['required', 'string', 'max:160'], 'definition' => ['required', 'array'], 'status' => ['nullable', 'in:active,disabled'], 'change_summary' => ['nullable', 'string', 'max:1000']];
    }
}
