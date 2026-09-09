<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\WorkflowRun;
use App\Services\ApiActor;
use App\Services\WorkflowAgentEvidence;
use Illuminate\Http\Request;

class WorkflowAgentEvidenceController extends Controller
{
    public function show(Request $request, string $runPublicId, int $stepId, ApiActor $actor, WorkflowAgentEvidence $evidence)
    {
        $run = WorkflowRun::where('user_id', $request->user()->id)->where('public_id', $runPublicId)->firstOrFail();
        $step = $run->steps()->where('user_id', $request->user()->id)->findOrFail($stepId);
        $deviceId = $actor->deviceId($request, null, true);
        $device = Device::where('user_id', $request->user()->id)->where('device_id', $deviceId)->whereNull('revoked_at')->firstOrFail();

        return response()->json(['data' => $evidence->snapshot($run, $step, $device)]);
    }
}
