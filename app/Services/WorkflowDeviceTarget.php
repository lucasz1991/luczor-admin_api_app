<?php

namespace App\Services;

use App\Models\Device;
use App\Models\WorkflowStep;

class WorkflowDeviceTarget
{
    public static function validate(mixed $target): void
    {
        abort_unless(is_array($target) && in_array($target['kind'] ?? null, ['current', 'coordinator', 'specific', 'capability'], true), 422, 'workflow_device_target_invalid');
        $allowed = match ($target['kind']) {
            'specific' => ['kind', 'device_id'],
            'capability' => ['kind', 'task_type', 'task_version'],
            default => ['kind'],
        };
        abort_unless(array_diff(array_keys($target), $allowed) === [], 422, 'workflow_device_target_fields_invalid');
        if ($target['kind'] === 'specific') {
            abort_unless(is_string($target['device_id'] ?? null) && strlen($target['device_id']) <= 120 && trim($target['device_id']) !== '', 422, 'workflow_device_target_id_invalid');
        }
        if ($target['kind'] === 'capability') {
            abort_unless(! isset($target['task_type']) || (is_string($target['task_type']) && WorkflowTaskCatalog::isClientTask($target['task_type'])), 422, 'workflow_device_target_capability_invalid');
            abort_unless(($target['task_version'] ?? 1) === 1, 422, 'workflow_device_target_version_invalid');
        }
    }

    public static function assertOwnedDefinition(int $userId, array $definition): void
    {
        foreach ($definition['steps'] ?? [] as $step) {
            $target = $step['device_target'] ?? null;
            if (($target['kind'] ?? null) === 'specific') {
                abort_unless(Device::where('user_id', $userId)->where('device_id', $target['device_id'])->whereNull('revoked_at')->exists(), 422, 'workflow_device_target_not_owned');
            }
            foreach (($step['payload']['branches'] ?? []) as $branch) {
                self::assertOwnedDefinition($userId, $branch);
            }
            if (isset($step['payload']['body'])) {
                self::assertOwnedDefinition($userId, $step['payload']['body']);
            }
        }
    }

    public static function selector(WorkflowStep $step): ?array
    {
        foreach ($step->run->definition_snapshot['definition']['steps'] ?? [] as $entry) {
            if (($entry['key'] ?? null) === $step->step_key) {
                return $entry['device_target'] ?? null;
            }
        }

        return null;
    }

    public static function forStep(WorkflowStep $step): string
    {
        if (isset($step->control_state['admitted_device_id'])) {
            return $step->control_state['admitted_device_id'];
        }
        $context = $step->run->context['_execution'] ?? [];
        $target = ! $step->run->root_workflow_run_id ? ($context['device_targets'][$step->step_key] ?? null) : null;
        if ($target !== null) {
            return $target;
        }
        $selector = self::selector($step);
        if ($selector !== null) {
            if ($selector['kind'] === 'current') {
                return (string) ($context['device_id'] ?? '');
            }
            if ($selector['kind'] === 'specific') {
                return $selector['device_id'];
            }
            if ($selector['kind'] === 'coordinator') {
                return (string) Device::where('user_id', $step->user_id)->whereNull('revoked_at')->whereKey($context['coordination_source_device_id'] ?? 0)->value('device_id');
            }
            // Only fresh, same-account devices with both the requested capability and the actual task qualify.
            $devices = Device::where('user_id', $step->user_id)->whereNull('revoked_at')->where('coordination_available', true)
                ->where('coordination_seen_at', '>', now()->subSeconds(DeviceLeadership::LEASE_SECONDS))->orderBy('id')->get()
                ->sortBy([fn ($a, $b) => (int) $a->coordination_busy <=> (int) $b->coordination_busy,
                    fn ($a, $b) => (int) ($b->meta['coordination']['model_tier'] ?? 0) <=> (int) ($a->meta['coordination']['model_tier'] ?? 0)]);
            foreach ($devices as $device) {
                $capabilities = app(WorkflowDeviceCapabilities::class);
                if ($capabilities->admission($device, $step->type, $step->type_version)['ready']
                    && $capabilities->admission($device, $selector['task_type'] ?? $step->type, $selector['task_version'] ?? $step->type_version)['ready']) {
                    return $device->device_id;
                }
            }

            return '';
        }

        return (string) ($step->resolved_payload['device_id'] ?? $step->payload['device_id'] ?? $context['device_id'] ?? '');
    }
}
