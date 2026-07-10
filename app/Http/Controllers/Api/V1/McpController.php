<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ToolCall;
use App\Services\ApiActor;
use App\Services\AuditLogger;
use App\Services\McpToolRegistry;
use App\Services\McpToolService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class McpController extends Controller
{
    public function tools(McpToolRegistry $tools)
    {
        return response()->json(['data' => $tools->all()]);
    }

    public function call(Request $request, ApiActor $actor, McpToolRegistry $registry, McpToolService $tools, AuditLogger $audit)
    {
        $data = $request->validate([
            'server' => ['required', 'string', 'max:80'],
            'tool' => ['required', 'string', 'max:120'],
            'input' => ['nullable', 'array'],
        ]);
        $descriptor = $registry->find($data['server'], $data['tool']);
        $apiKey = $request->attributes->get('apiKey');
        abort_unless($apiKey instanceof ApiKey && $apiKey->hasAbility($descriptor['ability']), 403, 'The API key does not have the required MCP scope.');

        $call = ToolCall::create([
            'user_id' => $actor->userId($request),
            'server' => $descriptor['server'],
            'tool' => $descriptor['tool'],
            'risk_level' => $descriptor['risk'],
            'status' => 'running',
            'input' => $data['input'] ?? [],
        ]);

        try {
            $result = $tools->execute($request, $descriptor, $data['input'] ?? []);
            $call->update([
                'project_id' => $result['project_id'],
                'device_job_id' => $result['device_job_id'],
                'status' => $result['status'],
                'output' => $result['output'],
                'result_hash' => $audit->hash($result['output']),
            ]);
            $audit->record([
                'actor_user_id' => $actor->userId($request),
                'project_id' => $result['project_id'],
                'device_job_id' => $result['device_job_id'],
                'tool_call_id' => $call->id,
                'event_type' => 'mcp.tool_called',
                'tool' => $descriptor['server'].'.'.$descriptor['tool'],
                'policy' => $descriptor['execution'],
                'approval' => $result['device_job_id'] ? 'device_job_policy' : 'not_required',
                'risk_level' => $descriptor['risk'],
                'outcome' => $result['status'],
                'payload' => $data['input'] ?? [],
                'result' => $result['output'],
            ]);

            return response()->json(['data' => $call->fresh()]);
        } catch (Throwable $error) {
            $message = $error instanceof HttpExceptionInterface ? $error->getMessage() : 'MCP tool execution failed.';
            $call->update(['status' => 'failed', 'output' => ['error' => $message], 'result_hash' => $audit->hash(['error' => $message])]);
            $audit->record([
                'actor_user_id' => $actor->userId($request),
                'tool_call_id' => $call->id,
                'event_type' => 'mcp.tool_called',
                'tool' => $descriptor['server'].'.'.$descriptor['tool'],
                'policy' => $descriptor['execution'],
                'risk_level' => $descriptor['risk'],
                'outcome' => 'failed',
                'payload' => $data['input'] ?? [],
                'result' => ['error' => $message],
            ]);
            throw $error;
        }
    }
}
