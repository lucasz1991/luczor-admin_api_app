<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowTestCase;
use App\Models\WorkflowTestEvidence;
use App\Services\CoordinatedDeviceJobs;
use App\Services\DeviceLeadership;
use App\Services\WorkflowTestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowMatrixController extends Controller
{
    public function store(Request $request, WorkflowDefinition $workflowDefinition, DeviceLeadership $leadership, WorkflowTestService $tests)
    {
        $source = $leadership->device($request);
        abort_unless((int) $workflowDefinition->user_id === (int) $source->user_id, 404);
        $data = $request->validate(['operation_id' => 'required|uuid', 'master_epoch' => 'required|integer|min:1',
            'expected_version' => 'required|integer|min:1', 'test_case_id' => 'required|integer|min:1',
            'mode' => 'required|in:definition,simulation,real', 'device_ids' => 'required|array|between:1,10',
            'device_ids.*' => 'required|string|max:120|distinct:strict']);
        $id = DB::transaction(function () use ($source, $workflowDefinition, $leadership, $tests, $data) {
            $leadership->fence($source, $data['master_epoch']);
            $hash = CoordinatedDeviceJobs::hash($data + ['definition_id' => $workflowDefinition->id]);
            $old = DB::table('workflow_test_matrices')->where('user_id', $source->user_id)->where('operation_id', $data['operation_id'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'workflow_matrix_operation_conflict');

                return $old->id;
            }
            $definition = WorkflowDefinition::whereKey($workflowDefinition->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $definition->version === $data['expected_version'], 409, 'workflow_version_conflict');
            $case = WorkflowTestCase::where('workflow_definition_id', $definition->id)->where('user_id', $source->user_id)->findOrFail($data['test_case_id']);
            $mirror = null;
            if ($data['mode'] === 'real') {
                $mirror = DB::table('project_mirror_heads')->where('project_id', $definition->project_id)->lockForUpdate()->first();
                abort_unless($mirror && $mirror->manifest_id && (int) $mirror->revision > 0, 409, 'workflow_matrix_project_mirror_required');
            }
            $testIds = [];
            foreach ($data['device_ids'] as $clientId) {
                $target = Device::where('user_id', $source->user_id)->where('device_id', $clientId)->whereNull('revoked_at')->firstOrFail();
                $testIds[] = $tests->start($definition, $case, $data['mode'], $target, null, [
                    'coordination_epoch' => $data['master_epoch'], 'coordination_source_device_id' => $source->id, 'matrix_device_ids' => $data['device_ids'],
                    'mirror_manifest_id' => $mirror?->manifest_id, 'mirror_revision' => $mirror ? (int) $mirror->revision : null])->id;
            }
            $id = (string) Str::uuid();
            DB::table('workflow_test_matrices')->insert(['id' => $id, 'user_id' => $source->user_id, 'workflow_definition_id' => $definition->id,
                'operation_id' => $data['operation_id'], 'request_hash' => $hash, 'definition_version' => $definition->version,
                'test_ids' => json_encode($testIds), 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        }, 3);

        return $this->show($request, $id, $leadership, $tests);
    }

    public function show(Request $request, string $matrixId, DeviceLeadership $leadership, WorkflowTestService $tests)
    {
        $device = $leadership->device($request);
        $matrix = DB::table('workflow_test_matrices')->where('user_id', $device->user_id)->where('id', $matrixId)->first();
        abort_unless($matrix !== null, 404);
        $rows = WorkflowTestEvidence::where('user_id', $device->user_id)->whereIn('id', json_decode($matrix->test_ids, true))->get()->map(fn ($test) => $tests->refresh($test));
        $status = $rows->contains('status', 'running') ? 'running' : ($rows->every(fn ($row) => $row->status === 'passed') ? 'passed' : 'failed');

        return response()->json(['data' => ['matrix_id' => $matrixId, 'definition_version' => (int) $matrix->definition_version,
            'mirror_manifest_id' => $rows->first()?->snapshot['mirror_manifest_id'] ?? null,
            'mirror_revision' => $rows->first()?->snapshot['mirror_revision'] ?? null,
            'status' => $status, 'tests' => $tests->serializeMany($rows)]]);
    }
}
