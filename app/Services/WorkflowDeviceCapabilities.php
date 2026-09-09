<?php

namespace App\Services;

use App\Models\Device;
use App\Models\WorkflowStep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WorkflowDeviceCapabilities
{
    public function report(Device $device, array $report): array
    {
        abort_unless(($report['schema_version'] ?? null) === 1 && is_string($report['environment_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $report['environment_hash']), 422, 'workflow_capability_report_invalid');
        $tasks = $report['tasks'] ?? null;
        abort_unless(is_array($tasks) && array_is_list($tasks) && count($tasks) <= 200, 422, 'workflow_capability_tasks_invalid');
        $seen = [];
        foreach ($tasks as $task) {
            abort_unless(is_array($task) && array_diff(array_keys($task), ['type', 'version', 'adapter', 'available', 'reason']) === [], 422, 'workflow_capability_task_invalid');
            $contract = WorkflowTaskCatalog::task(is_string($task['type'] ?? null) ? $task['type'] : '');
            abort_unless($contract && ($task['version'] ?? null) === $contract['version']
                && in_array($task['adapter'] ?? null, $contract['adapters'], true)
                && is_bool($task['available'] ?? null), 422, 'workflow_capability_contract_invalid');
            abort_if(isset($seen[$task['type']]), 422, 'workflow_capability_duplicate');
            $seen[$task['type']] = true;
            abort_if(isset($task['reason']) && (! is_string($task['reason']) || strlen($task['reason']) > 120), 422, 'workflow_capability_reason_invalid');
        }
        $stored = ['schema_version' => 1, 'reported_at' => now()->toIso8601String(), 'environment_hash' => $report['environment_hash'], 'tasks' => $tasks];
        DB::transaction(function () use ($device, $stored) {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            abort_if($device->revoked_at !== null, 403, 'Device is revoked.');
            $device->update(['meta' => array_merge($device->meta ?? [], ['workflow_capabilities' => $stored])]);
        });

        return $stored;
    }

    public function admission(Device $device, string $type, int $version): array
    {
        $report = $device->meta['workflow_capabilities'] ?? [];
        $date = $report['reported_at'] ?? null;
        try {
            $fresh = is_string($date) && $date !== '' && Carbon::parse($date)->between(now()->subMinutes(30), now()->addSeconds(30));
        } catch (\Throwable) {
            $fresh = false;
        }
        if ($device->revoked_at || ! in_array($device->status, ['online', 'busy'], true) || ! $fresh) {
            return ['ready' => false, 'reason' => 'workflow_device_capability_unavailable'];
        }
        $contract = WorkflowTaskCatalog::task($type);
        $matching = array_values(array_filter($report['tasks'] ?? [], fn ($task) => ($task['type'] ?? null) === $type));
        if (count($matching) !== 1 || ($matching[0]['version'] ?? null) !== $version
            || ($matching[0]['available'] ?? false) !== true || ! in_array($matching[0]['adapter'] ?? null, $contract['adapters'] ?? [], true)) {
            return ['ready' => false, 'reason' => 'workflow_task_capability_unavailable'];
        }

        return ['ready' => true, 'adapter' => $matching[0]['adapter'], 'environment_hash' => $report['environment_hash']];
    }

    public function required(WorkflowStep $step): bool
    {
        return ($step->run->definition_snapshot['definition']['schema_version'] ?? 1) >= 2;
    }
}
