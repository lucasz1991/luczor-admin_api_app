<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Policy;

/** Fixed remote operation profiles. Arbitrary processes, shells and paths are never valid jobs. */
class DeviceToolPolicy
{
    /** @var array<string,string> */
    private const RISKS = [
        'desktop.capture_screen' => 'sensitive',
        'desktop.clipboard.read' => 'sensitive',
        'desktop.windows.list' => 'low',
        'desktop.input.move_mouse' => 'critical',
        'desktop.input.click' => 'critical',
        'desktop.input.type_text' => 'critical',
        'desktop.input.press_key' => 'critical',
        'desktop.open_url' => 'normal',
        // SOLL §14 P15b — a vetted workflow client task compiled into a bundle;
        // the task_key is re-validated against the WorkflowTaskCatalog.
        'workflow.task' => 'sensitive',
    ];

    /** @param array<string,mixed> $payload */
    public function normalize(string $tool, array $payload): array
    {
        abort_unless(array_key_exists($tool, self::RISKS), 422, 'The device tool profile is not registered.');

        return match ($tool) {
            'workflow.task' => $this->workflowTask($payload),
            'desktop.input.move_mouse' => [
                'x' => $this->coordinate($payload['x'] ?? null),
                'y' => $this->coordinate($payload['y'] ?? null),
            ],
            'desktop.input.click' => [
                'button' => in_array($payload['button'] ?? 'left', ['left', 'right', 'middle'], true) ? $payload['button'] ?? 'left' : abort(422, 'Invalid mouse button.'),
                'x' => array_key_exists('x', $payload) ? $this->coordinate($payload['x']) : null,
                'y' => array_key_exists('y', $payload) ? $this->coordinate($payload['y']) : null,
                'double' => (bool) ($payload['double'] ?? false),
            ],
            'desktop.input.type_text' => ['text' => $this->string($payload['text'] ?? null, 10000)],
            'desktop.input.press_key' => ['key' => $this->key($payload['key'] ?? null)],
            'desktop.open_url' => ['url' => $this->url($payload['url'] ?? null)],
            default => [],
        };
    }

    /** @param array<string,mixed> $payload */
    public function risk(string $tool, array $payload = []): string
    {
        // P15b — a workflow bundle is as risky as its catalogued task contract.
        if ($tool === 'workflow.task') {
            $task = WorkflowTaskCatalog::task((string) ($payload['task_key'] ?? '')) ?? [];

            return match (true) {
                (bool) ($task['requires_approval'] ?? true) => 'sensitive',
                (bool) ($task['mutating'] ?? false) => 'normal',
                default => 'low',
            };
        }

        return self::RISKS[$tool] ?? 'critical';
    }

    /**
     * P15b — bundle payload for a workflow client task: only catalogued client
     * tasks, bounded params, plus the workflow back-reference for the device.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function workflowTask(array $payload): array
    {
        $key = (string) ($payload['task_key'] ?? '');
        abort_unless(WorkflowTaskCatalog::isClientTask($key), 422, 'The workflow client task is not in the catalog.');
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        abort_unless(strlen((string) json_encode($params)) <= 20000, 422, 'Workflow task params are too large.');
        $workflow = is_array($payload['workflow'] ?? null) ? $payload['workflow'] : [];

        return [
            'task_key' => $key,
            'params' => $params,
            'workflow' => [
                'run' => (string) ($workflow['run'] ?? ''),
                'step_id' => (int) ($workflow['step_id'] ?? 0),
                'step_key' => (string) ($workflow['step_key'] ?? ''),
            ],
        ];
    }

    public function requiresLocalApproval(int $userId, ?int $projectId, Device $device, string $tool): bool
    {
        $policy = Policy::query()
            ->where('type', 'tool')
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))
            ->orderByDesc('project_id')
            ->orderByDesc('user_id')
            ->get()
            ->first(function (Policy $candidate) use ($device, $tool) {
                $rules = $candidate->rules ?? [];

                return ($rules['allow_unattended'] ?? false) === true
                    && in_array($tool, (array) ($rules['tools'] ?? []), true)
                    && in_array($device->device_id, (array) ($rules['device_ids'] ?? []), true);
            });

        return $policy === null;
    }

    private function coordinate(mixed $value): int
    {
        abort_unless(is_numeric($value) && (int) $value >= -100000 && (int) $value <= 100000, 422, 'Invalid coordinate.');

        return (int) $value;
    }

    private function string(mixed $value, int $max): string
    {
        abort_unless(is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max, 422, 'Invalid text payload.');

        return $value;
    }

    private function key(mixed $value): string
    {
        $key = strtolower($this->string($value, 20));
        abort_unless(in_array($key, ['enter', 'return', 'tab', 'escape', 'esc', 'space', 'backspace', 'delete', 'del', 'up', 'down', 'left', 'right', 'home', 'end'], true) || mb_strlen($key) === 1, 422, 'Invalid key.');

        return $key;
    }

    private function url(mixed $value): string
    {
        $url = $this->string($value, 2048);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        abort_unless(in_array($scheme, ['https', 'http'], true), 422, 'Only http(s) URLs may be opened remotely.');

        return $url;
    }
}
