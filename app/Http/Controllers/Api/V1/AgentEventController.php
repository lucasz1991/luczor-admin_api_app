<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LuczorAgentEventArchive;
use App\Models\LlmRun;
use App\Models\ToolCall;
use App\Services\ApiActor;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AgentEventController extends Controller
{
    public function store(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'external_id' => ['nullable', 'string', 'max:190'],
            'event_type' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'string', 'max:120'],
            'payload' => ['required', 'array'],
            'occurred_at_client' => ['nullable'],
        ]);

        $project = $actor->project($request, $data['project_id'] ?? null);
        $event = LuczorAgentEventArchive::create([
            'user_id' => $actor->userId($request),
            'client_id' => $actor->deviceId($request, $data['client_id'], true),
            'project_ref_id' => $project?->id,
            'external_id' => $data['external_id'] ?? null,
            'event_type' => $data['event_type'] ?? 'event',
            'payload' => $data['payload'],
            'occurred_at_client' => $this->clientTime($data['occurred_at_client'] ?? null),
        ]);

        if (str_starts_with((string) ($data['event_type'] ?? ''), 'tool.')) {
            $payload = $data['payload'];
            $run = ! empty($payload['llm_request_id']) ? LlmRun::query()
                ->where('request_id', $payload['llm_request_id'])
                ->where('user_id', $actor->userId($request))->first() : null;
            ToolCall::create([
                'user_id' => $actor->userId($request), 'project_id' => $project?->id,
                'llm_run_id' => $run?->id, 'server' => 'desktop', 'tool' => (string) ($payload['tool'] ?? 'unknown'),
                'risk_level' => 'device', 'status' => str_replace('tool.', '', (string) $data['event_type']),
                'duration_ms' => isset($payload['duration_ms']) ? (int) $payload['duration_ms'] : null,
                'input' => ['call_id' => $payload['call_id'] ?? null],
                'output' => isset($payload['output']) ? ['summary' => $payload['output']] : null,
                'error' => $payload['error'] ?? null, 'started_at' => $event->occurred_at_client,
                'finished_at' => $event->occurred_at_client,
                'result_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ]);
            if ($run) $run->update(['tool_call_count' => $run->tool_call_count + 1]);
        }

        return response()->json([
            'ok' => true,
            'id' => $event->id,
        ], 201);
    }

    private function clientTime($value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        if (is_numeric($value)) {
            $number = (int) $value;
            return $number > 9999999999 ? Carbon::createFromTimestampMs($number) : Carbon::createFromTimestamp($number);
        }

        return Carbon::parse($value);
    }
}
