<?php

namespace App\Services;

use App\Models\WorkflowRunArtifact;
use App\Models\WorkflowStep;

class WorkflowArtifactService
{
    /** @param array<string,mixed> $data */
    public function record(WorkflowStep $step, array $data): WorkflowRunArtifact
    {
        return WorkflowRunArtifact::create([
            'workflow_run_id' => $step->workflow_run_id,
            'workflow_step_id' => $step->id,
            'step_key' => $step->step_key,
            'phase' => $data['phase'] ?? 'after',
            'artifact_type' => $data['artifact_type'] ?? 'json',
            'label' => $data['label'] ?? null,
            'current_url' => $data['current_url'] ?? null,
            'storage_disk' => $data['storage_disk'] ?? 'local',
            'storage_path' => $data['storage_path'] ?? null,
            'status' => $data['status'] ?? 'success',
            'error_message' => $data['error_message'] ?? null,
            'metadata' => $this->maskSecrets(is_array($data['metadata'] ?? null) ? $data['metadata'] : []),
        ]);
    }

    /**
     * @param  array<mixed,mixed>  $data
     * @return array<mixed,mixed>
     */
    public function maskSecrets(array $data): array
    {
        $secret = '/(pass(word)?|secret|token|api[_-]?key|session|authorization|cookie|ws_?endpoint)/i';
        $redacted = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match($secret, $key)) {
                $redacted[$key] = '***';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->maskSecrets($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }
}
