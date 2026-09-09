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
        'workspace.chat' => 'sensitive',
    ];

    /** @param array<string,mixed> $payload */
    public function normalize(string $tool, array $payload): array
    {
        abort_unless(array_key_exists($tool, self::RISKS), 422, 'The device tool profile is not registered.');

        return match ($tool) {
            'workflow.task' => $this->workflowTask($payload),
            'workspace.chat' => $this->workspaceChat($payload),
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
        abort_unless(($payload['task_version'] ?? 1) === 1, 422, 'Unsupported workflow task version.');
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        abort_unless(strlen((string) json_encode($params)) <= 20000, 422, 'Workflow task params are too large.');
        $workflow = is_array($payload['workflow'] ?? null) ? $payload['workflow'] : [];

        return [
            'task_key' => $key,
            'task_version' => $payload['task_version'] ?? 1,
            'params' => $params,
            'workflow' => [
                'run' => (string) ($workflow['run'] ?? ''),
                'step_id' => (int) ($workflow['step_id'] ?? 0),
                'step_key' => (string) ($workflow['step_key'] ?? ''),
                'execution_id' => (string) ($workflow['execution_id'] ?? ''),
                'definition_id' => (int) ($workflow['definition_id'] ?? 0),
                'revision' => (int) ($workflow['revision'] ?? 1),
                'child_definition_id' => (int) ($workflow['child_definition_id'] ?? 0),
                'child_revision' => (int) ($workflow['child_revision'] ?? 1),
                'project_id' => $workflow['project_id'] ?? null,
                'device_id' => (string) ($workflow['device_id'] ?? ''),
                'file_scope' => $workflow['file_scope'] ?? 'legacy',
                'workspace_root_id' => $workflow['workspace_root_id'] ?? null,
                'workspace_root_path' => $workflow['workspace_root_path'] ?? null,
                'input_sources' => array_values((array) ($workflow['input_sources'] ?? [])),
                'output_keys' => array_values((array) ($workflow['output_keys'] ?? [])),
                'automatic' => (bool) ($workflow['automatic'] ?? false),
                'grant' => is_array($workflow['grant'] ?? null) ? $workflow['grant'] : null,
                'test_mode' => in_array($workflow['test_mode'] ?? null, ['definition', 'simulation', 'real'], true) ? $workflow['test_mode'] : null,
                'test_binding' => is_array($workflow['test_binding'] ?? null) ? $workflow['test_binding'] : null,
                'test_run' => is_string($workflow['test_run'] ?? null) ? $workflow['test_run'] : null,
                'thinking_tier' => in_array($workflow['thinking_tier'] ?? null, ['fast', 'balanced', 'thorough', 'max', 'ultra'], true) ? $workflow['thinking_tier'] : 'balanced',
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

    private function workspaceChat(array $payload): array
    {
        $validated = validator($payload, [
            'user_id' => ['required', 'integer', 'min:1'],
            'device_id' => ['required', 'string', 'max:255'],
            'chat_id' => ['required', 'integer', 'min:1'],
            'scope' => ['required', 'in:workspace,personal'],
            'prompt' => ['required', 'string', 'max:12000'],
            'history' => ['present', 'array', 'max:8'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:3000'],
            'personal_memories' => ['present', 'array', 'max:8'],
            'personal_memories.*.id' => ['required', 'string', 'max:255'],
            'personal_memories.*.content' => ['required', 'string', 'max:1500'],
            'personal_memories.*.priority' => ['required', 'in:background,normal,high,critical'],
        ])->validate();
        // Explicit fields only: no caller-supplied system prompt, project path or provider settings.
        $validated['history'] = array_map(fn ($message) => array_intersect_key($message, array_flip(['role', 'content'])), $validated['history']);
        $validated['personal_memories'] = array_map(fn ($memory) => array_intersect_key($memory, array_flip(['id', 'content', 'priority'])), $validated['personal_memories']);
        abort_unless(mb_strlen(json_encode($validated, JSON_UNESCAPED_UNICODE)) <= 23000, 422, 'Der Chatkontext ist zu groß. Bitte einen neuen Chat starten.');

        return $validated;
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
