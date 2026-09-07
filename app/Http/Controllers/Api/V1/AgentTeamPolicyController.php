<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AgentTeamPolicyService;

class AgentTeamPolicyController extends Controller
{
    public function __invoke(AgentTeamPolicyService $policy)
    {
        return response()->json($policy->payload())->header('Cache-Control', 'private, no-store');
    }
}
