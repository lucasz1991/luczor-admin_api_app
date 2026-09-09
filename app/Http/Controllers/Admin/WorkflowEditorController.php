<?php

namespace App\Http\Controllers\Admin;

use App\Models\WorkflowDefinition;
use App\Models\WorkflowOperation;
use App\Models\WorkflowRepairRevision;
use App\Models\WorkflowRun;
use App\Models\WorkflowTestCase;
use App\Models\WorkflowTestEvidence;
use App\Models\WorkflowTrigger;
use App\Services\WorkflowAuthoringService;
use App\Services\WorkflowRepairService;
use App\Services\WorkflowTaskCatalog;
use App\Services\WorkflowTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Session adapter for the same authoring boundary used by the desktop. */
class WorkflowEditorController extends AdminController
{
    public function state(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring): JsonResponse
    {
        $this->owned($request, $workflowDefinition);
        $owner = (int) $request->user()->id;

        return response()->json([
            'workflow' => $authoring->serialize($workflowDefinition, true),
            'catalog' => WorkflowTaskCatalog::options(),
            'testCases' => WorkflowTestCase::where('user_id', $owner)->where('workflow_definition_id', $workflowDefinition->id)->latest('id')->limit(100)->get(),
            'tests' => WorkflowTestEvidence::where('user_id', $owner)->where('workflow_definition_id', $workflowDefinition->id)->latest('id')->limit(100)->get([
                'id', 'workflow_test_case_id', 'workflow_run_id', 'repair_revision_id', 'mode', 'status', 'result', 'definition_hash', 'code_hash', 'assertions_hash', 'fixture_hash', 'environment_hash', 'created_at', 'finished_at',
            ]),
            'repairs' => WorkflowRepairRevision::where('user_id', $owner)->where('workflow_definition_id', $workflowDefinition->id)->latest('id')->limit(100)->get([
                'id', 'source_run_id', 'base_version', 'definition', 'definition_hash', 'code_hash', 'status', 'activated_version', 'created_at',
            ]),
            'runs' => WorkflowRun::where('user_id', $owner)->where('workflow_definition_id', $workflowDefinition->id)->latest('id')->limit(50)->get([
                'id', 'public_id', 'definition_version', 'status', 'sandbox', 'test_mode', 'started_at', 'finished_at', 'created_at',
            ]),
            'triggers' => WorkflowTrigger::where('user_id', $owner)->where('workflow_definition_id', $workflowDefinition->id)->latest('id')->limit(100)->get([
                'id', 'name', 'kind', 'enabled', 'config', 'next_due_at', 'created_at',
            ]),
            'capabilities' => ['deviceBound' => false, 'canRunRealTests' => false, 'canConfigureRepairPolicy' => false],
            'urls' => [
                'save' => route('dashboard.workflows.update', $workflowDefinition),
                'testCases' => route('dashboard.workflows.editor.test-cases', $workflowDefinition),
                'tests' => route('dashboard.workflows.editor.tests', $workflowDefinition),
                'repairs' => route('dashboard.workflows.editor.repairs', $workflowDefinition),
                'operation' => url('/dashboard/workflows/'.$workflowDefinition->id.'/operations'),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring): JsonResponse
    {
        $this->owned($request, $workflowDefinition);
        $data = $request->validate([
            'name' => 'required|string|max:160', 'definition_json' => 'required|string|json|max:200000',
            'expected_version' => 'required|integer|min:1', 'operation_id' => 'required|uuid',
        ]);
        $definition = json_decode($data['definition_json'], true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($definition) && isset($definition['steps']), 422, 'workflow_definition_required');
        $saved = $authoring->save((int) $request->user()->id, [
            'name' => $data['name'], 'definition' => $definition,
            'expected_version' => $data['expected_version'], 'operation_id' => $data['operation_id'],
        ], $workflowDefinition->id);

        return response()->json(['workflow' => $saved]);
    }

    public function storeCase(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring, WorkflowTestService $tests): JsonResponse
    {
        $this->owned($request, $workflowDefinition);
        $data = $request->validate([
            'operation_id' => 'required|uuid', 'name' => 'required|string|max:160', 'specification' => 'required|array',
            'local_approved' => 'prohibited', 'device_id' => 'prohibited',
        ]);
        abort_unless(($data['specification']['real_test_authorized'] ?? false) === false, 422, 'workflow_real_test_device_approval_required');
        $result = $authoring->operate((int) $request->user()->id, $data['operation_id'], 'test_case.create', $data + ['definition_id' => $workflowDefinition->id],
            fn () => $tests->createCase($workflowDefinition, $data)->toArray());

        return response()->json(['data' => $result], 201);
    }

    public function startTest(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring, WorkflowTestService $tests): JsonResponse
    {
        $this->owned($request, $workflowDefinition);
        $data = $request->validate([
            'operation_id' => 'required|uuid', 'mode' => 'required|in:definition,simulation', 'test_case_id' => 'required|integer|min:1',
            'expected_version' => 'required|integer|min:1', 'repair_revision_id' => 'nullable|integer|min:1',
            'device_id' => 'prohibited', 'local_approved' => 'prohibited',
        ]);
        $result = $authoring->operate((int) $request->user()->id, $data['operation_id'], 'test.start', $data + ['definition_id' => $workflowDefinition->id], function () use ($data, $workflowDefinition, $tests) {
            $definition = $workflowDefinition->fresh();
            abort_unless((int) $definition->version === $data['expected_version'], 409, 'workflow_version_conflict');
            $case = WorkflowTestCase::where('user_id', $definition->user_id)->where('workflow_definition_id', $definition->id)->findOrFail($data['test_case_id']);
            $repair = empty($data['repair_revision_id']) ? null : WorkflowRepairRevision::where('user_id', $definition->user_id)->where('workflow_definition_id', $definition->id)->findOrFail($data['repair_revision_id']);

            return $tests->start($definition, $case, $data['mode'], null, $repair)->toArray();
        });

        return response()->json(['data' => $result], 201);
    }

    public function propose(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring, WorkflowRepairService $repairs): JsonResponse
    {
        $this->owned($request, $workflowDefinition);
        $data = $request->validate([
            'operation_id' => 'required|uuid', 'source_run_id' => 'required|integer|min:1', 'expected_version' => 'required|integer|min:1', 'definition' => 'required|array',
            'device_id' => 'prohibited', 'local_approved' => 'prohibited',
        ]);
        $result = $authoring->operate((int) $request->user()->id, $data['operation_id'], 'repair.propose', $data + ['definition_id' => $workflowDefinition->id], function () use ($data, $workflowDefinition, $repairs) {
            $run = WorkflowRun::where('user_id', $workflowDefinition->user_id)->where('workflow_definition_id', $workflowDefinition->id)->findOrFail($data['source_run_id']);

            return $repairs->propose($workflowDefinition, $run, $data['definition'], $data['expected_version'])->toArray();
        });

        return response()->json(['data' => $result], 201);
    }

    public function operation(Request $request, WorkflowDefinition $workflowDefinition, string $operationId): JsonResponse
    {
        $this->owned($request, $workflowDefinition);
        $operation = WorkflowOperation::where('user_id', $request->user()->id)->where('operation_id', $operationId)->first();
        $data = $operation?->response;
        $workflowId = match ($operation?->action) {
            'update' => $data['id'] ?? null,
            'test_case.create', 'test.start', 'repair.propose' => $data['workflow_definition_id'] ?? null,
            default => null,
        };
        if ((int) $workflowId !== (int) $workflowDefinition->id) {
            return response()->json(['status' => 'not_found'])->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['status' => $operation->status,
            'response' => $operation->action === 'update' ? ['workflow' => $data] : ['data' => $data],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function owned(Request $request, WorkflowDefinition $definition): void
    {
        $this->ensureAdmin($request);
        abort_unless((int) $definition->user_id === (int) $request->user()->id, 404);
    }
}
