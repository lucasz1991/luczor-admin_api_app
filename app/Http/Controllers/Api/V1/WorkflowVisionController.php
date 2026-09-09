<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WorkflowVisionRequest;
use App\Services\Proxy\WorkflowVisionPolicy;
use App\Services\Proxy\WorkflowVisionService;

final class WorkflowVisionController extends Controller
{
    public function capabilities(WorkflowVisionPolicy $policy)
    {
        return response()->json(['data' => $policy->capabilities()]);
    }

    public function infer(WorkflowVisionRequest $request, WorkflowVisionService $vision)
    {
        return response()->json(['data' => $vision->infer($request)]);
    }
}
