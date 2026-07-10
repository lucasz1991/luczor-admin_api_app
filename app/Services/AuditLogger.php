<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Support\Str;

class AuditLogger
{
    /** @param array<string,mixed> $payload */
    public function record(array $payload): AuditEvent
    {
        $body = $payload['payload'] ?? [];
        $result = $payload['result'] ?? null;

        return AuditEvent::create([
            'event_id' => (string) Str::uuid(),
            'actor_user_id' => $payload['actor_user_id'] ?? null,
            'device_id' => $payload['device_id'] ?? null,
            'project_id' => $payload['project_id'] ?? null,
            'device_job_id' => $payload['device_job_id'] ?? null,
            'tool_call_id' => $payload['tool_call_id'] ?? null,
            'event_type' => $payload['event_type'],
            'tool' => $payload['tool'] ?? null,
            'policy' => $payload['policy'] ?? null,
            'approval' => $payload['approval'] ?? null,
            'risk_level' => $payload['risk_level'] ?? 'normal',
            'outcome' => $payload['outcome'] ?? 'recorded',
            'payload_hash' => $this->hash($body),
            'result_hash' => $result === null ? null : $this->hash($result),
            'payload' => $body,
        ]);
    }

    public function hash(mixed $value): string
    {
        return hash('sha256', json_encode($this->sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sort($item);
        }

        return $value;
    }
}
