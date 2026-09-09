<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRepairRevision;
use App\Models\WorkflowRun;
use App\Models\WorkflowTestCase;
use App\Models\WorkflowTestEvidence;
use App\Services\ApiActor;
use App\Services\WorkflowAuthoringService;
use App\Services\WorkflowDeviceCapabilities;
use App\Services\WorkflowRepairService;
use App\Services\WorkflowTestService;
use Illuminate\Http\Request;

class WorkflowExecutionController extends Controller
{
    public function capabilities(Request $request, ApiActor $actor, WorkflowDeviceCapabilities $capabilities)
    {
        $data = $request->validate(['device_id' => 'required|string|max:120', 'capabilities' => 'required|array']);
        $deviceId = $actor->deviceId($request, $data['device_id'], true);
        $device = Device::where('user_id', $request->user()->id)->where('device_id', $deviceId)->whereNull('revoked_at')->firstOrFail();

        return response()->json(['data' => $capabilities->report($device, $data['capabilities'])]);
    }

    public function cases(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $this->owned($request, $workflowDefinition);

        return response()->json(['data' => WorkflowTestCase::where('workflow_definition_id', $workflowDefinition->id)->latest('id')->limit(100)->get()]);
    }

    public function storeCase(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring, WorkflowTestService $tests)
    {
        $this->owned($request, $workflowDefinition);
        $data = $request->validate(['operation_id' => 'required|uuid', 'name' => 'required|string|max:160', 'specification' => 'required|array']);
        $result = $authoring->operate((int) $request->user()->id, $data['operation_id'], 'test_case.create', $data + ['definition_id' => $workflowDefinition->id], fn () => $tests->createCase($workflowDefinition, $data)->toArray());

        return response()->json(['data' => $result], 201);
    }

    public function tests(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $this->owned($request, $workflowDefinition);

        return response()->json(['data' => WorkflowTestEvidence::where('workflow_definition_id', $workflowDefinition->id)->latest('id')->limit(100)->get()]);
    }

    public function startTest(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring, WorkflowTestService $tests)
    {
        $this->owned($request, $workflowDefinition);
        $data = $request->validate(['operation_id' => 'required|uuid', 'mode' => 'required|in:definition,simulation,real', 'test_case_id' => 'required|integer|min:1',
            'device_id' => 'nullable|string|max:120', 'repair_revision_id' => 'nullable|integer|min:1', 'expected_version' => 'required|integer|min:1']);
        $result = $authoring->operate((int) $request->user()->id, $data['operation_id'], 'test.start', $data + ['definition_id' => $workflowDefinition->id], function () use ($data, $tests, $workflowDefinition) {
            $definition = $workflowDefinition->fresh();
            abort_unless((int) $definition->version === $data['expected_version'], 409, 'workflow_version_conflict');
            $case = WorkflowTestCase::where('workflow_definition_id', $definition->id)->findOrFail($data['test_case_id']);
            $repair = empty($data['repair_revision_id']) ? null : WorkflowRepairRevision::where('workflow_definition_id', $definition->id)->findOrFail($data['repair_revision_id']);
            $device = empty($data['device_id']) ? null : Device::where('user_id', $definition->user_id)->where('device_id', $data['device_id'])->whereNull('revoked_at')->firstOrFail();

            return $tests->serialize($tests->start($definition, $case, $data['mode'], $device, $repair));
        });

        return response()->json(['data' => $result], 201);
    }

    public function test(Request $request, WorkflowTestEvidence $workflowTest, WorkflowTestService $tests)
    {
        $this->owned($request, $workflowTest);

        return response()->json(['data' => $tests->serialize($tests->refresh($workflowTest))]);
    }

    public function policy(Request $request, WorkflowDefinition $workflowDefinition, ApiActor $actor, WorkflowAuthoringService $authoring, WorkflowRepairService $repairs)
    {
        $this->owned($request, $workflowDefinition);
        $data = $request->validate(['operation_id' => 'required|uuid', 'expected_version' => 'required|integer|min:1', 'enabled' => 'required|boolean', 'auto_activate' => 'sometimes|boolean',
            'allow_script_repair' => 'sometimes|boolean', 'max_repairs' => 'sometimes|integer|min:0|max:2', 'test_case_id' => 'required|integer|min:1',
            'device_id' => 'required|string|max:120', 'local_approved' => 'required|accepted']);
        $deviceId = $actor->deviceId($request, $data['device_id'], true);
        $data['local_approved'] = $request->boolean('local_approved');
        $result = $authoring->operate((int) $request->user()->id, $data['operation_id'], 'repair.configure', $data + ['definition_id' => $workflowDefinition->id], fn () => $repairs->configure($workflowDefinition->fresh(), $data, $deviceId));

        return response()->json(['data' => $result]);
    }

    public function repairs(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $this->owned($request, $workflowDefinition);

        return response()->json(['data' => WorkflowRepairRevision::where('workflow_definition_id', $workflowDefinition->id)->latest('id')->limit(100)->get()]);
    }

    public function propose(Request $request, WorkflowDefinition $workflowDefinition, WorkflowAuthoringService $authoring, WorkflowRepairService $repairs)
    {
        $this->owned($request, $workflowDefinition);
        $data = $request->validate(['operation_id' => 'required|uuid', 'source_run_id' => 'required|integer|min:1', 'expected_version' => 'required|integer|min:1', 'definition' => 'required|array']);
        $result = $authoring->operate((int) $request->user()->id, $data['operation_id'], 'repair.propose', $data + ['definition_id' => $workflowDefinition->id], function () use ($repairs, $workflowDefinition, $data) {
            $run = WorkflowRun::where('user_id', $workflowDefinition->user_id)->findOrFail($data['source_run_id']);

            return $repairs->propose($workflowDefinition, $run, $data['definition'], $data['expected_version'])->toArray();
        });

        return response()->json(['data' => $result], 201);
    }

    public function activate(Request $request, WorkflowRepairRevision $workflowRepair, WorkflowAuthoringService $authoring, WorkflowRepairService $repairs)
    {
        $this->owned($request, $workflowRepair);
        $data = $request->validate(['operation_id' => 'required|uuid']);
        $result = $authoring->operate((int) $request->user()->id, $data['operation_id'], 'repair.activate', $data + ['repair_id' => $workflowRepair->id], fn () => $repairs->activate($workflowRepair)->toArray());

        return response()->json(['data' => $result]);
    }

    private function owned(Request $request, mixed $model): void
    {
        abort_unless((int) $model->user_id === (int) $request->user()->id, 404);
    }
}
