<?php

namespace App\Services;

/**
 * SOLL §14 P12 — central, vetted workflow task library (adapted from AI User
 * Factory's WorkflowTaskCatalog). This is the SINGLE source of truth for which
 * tasks a workflow definition (and the AI) may reference — "die AI nutzt nur
 * freigegebene Tasks".
 *
 * Two runner classes preserve Luczor's security posture:
 *  - runner=server: executed by WorkflowStepExecutor (declarative, no shell/node).
 *  - runner=client: the executor compiles the step into a device_job bundle; the
 *    device executes it and reports the result, never the server (P15b).
 *
 * `allowed_in_definition` gates what assertDefinition() accepts. Since P15b every
 * catalogued task has an executor path (server branch or device_job bundle), so
 * the whole library is definition-ready.
 *
 * `auto_dispatch` marks types advance() may hand to the executor unattended;
 * llm/manual/approval/device_job stay externally completed (API/approval).
 */
class WorkflowTaskCatalog
{
    /** @return array<string,array<string,mixed>> keyed by task_key */
    public static function all(): array
    {
        return [
            // ── Server-safe step types (original seven) ──────────────────────
            'context' => self::entry('Kontext abrufen', 'server', 'data', true, ['auto_dispatch' => true]),
            'llm' => self::entry('LLM-Aufruf', 'server', 'ai', true),
            'evaluator' => self::entry('Evaluator', 'server', 'data', true, ['auto_dispatch' => true]),
            'review' => self::entry('Review', 'server', 'data', true, ['auto_dispatch' => true]),
            'device_job' => self::entry('Geräte-Job', 'client', 'device', true, ['mutating' => true, 'requires_approval' => true]),
            'approval' => self::entry('Freigabe', 'server', 'control', true, ['requires_approval' => true]),
            'manual' => self::entry('Manueller Schritt', 'server', 'control', true),
            'workflow' => self::entry('Verschachtelter Workflow', 'server', 'workflow', true, [
                'auto_dispatch' => true,
                'params' => ['workflow_definition_id' => ['type' => 'number']],
            ]),

            // ── Server tasks with their own executor branch (P15b) ───────────
            'wait.seconds' => self::entry('Warten (Sekunden)', 'server', 'control', true, [
                'auto_dispatch' => true,
                // Above the max wait so expireTimedOutSteps never kills a wait.
                'timeout_seconds' => 3660,
                'params' => ['seconds' => ['type' => 'number', 'default' => 5, 'min' => 1, 'max' => 3600]],
            ]),
            'memory.remember' => self::entry('Erinnerung speichern', 'server', 'memory', true, [
                'auto_dispatch' => true,
                'params' => ['content' => ['type' => 'textarea'], 'scope' => ['type' => 'string', 'default' => 'project']],
                'mutating' => true,
            ]),
            'memory.recall' => self::entry('Erinnerung abrufen', 'server', 'memory', true, [
                'auto_dispatch' => true,
                'params' => ['query' => ['type' => 'string'], 'top_k' => ['type' => 'number', 'default' => 6]],
            ]),
            'task.create' => self::entry('Aufgabe anlegen', 'server', 'data', true, [
                'auto_dispatch' => true,
                'params' => ['title' => ['type' => 'string'], 'priority' => ['type' => 'string', 'default' => 'normal']],
                'mutating' => true,
            ]),

            // ── Client/device tasks (runner=client → device_job bundle) ──────
            'browser.open' => self::entry('Browser öffnen', 'client', 'browser', true, ['auto_dispatch' => true, 'mutating' => true]),
            'browser.open_url' => self::entry('URL öffnen', 'client', 'browser', true, [
                'auto_dispatch' => true, 'mutating' => true, 'params' => ['url' => ['type' => 'string']],
            ]),
            'browser.click' => self::entry('Element klicken', 'client', 'browser', true, [
                'auto_dispatch' => true, 'mutating' => true, 'params' => ['selector' => ['type' => 'string']],
            ]),
            'browser.read' => self::entry('Seite/Element lesen', 'client', 'browser', true, [
                'auto_dispatch' => true, 'params' => ['selector' => ['type' => 'string']],
            ]),
            'file.read' => self::entry('Datei lesen', 'client', 'file', true, [
                'auto_dispatch' => true, 'params' => ['path' => ['type' => 'string']],
            ]),
            'file.write' => self::entry('Datei schreiben', 'client', 'file', true, [
                'auto_dispatch' => true, 'mutating' => true, 'requires_approval' => true,
                'params' => ['path' => ['type' => 'string'], 'content' => ['type' => 'textarea']],
            ]),
            'api.call' => self::entry('API-Aufruf', 'client', 'api', true, [
                'auto_dispatch' => true, 'mutating' => true,
                'params' => ['method' => ['type' => 'string', 'default' => 'GET'], 'url' => ['type' => 'string']],
            ]),
            'python.run' => self::entry('Python ausführen', 'client', 'code', true, ['auto_dispatch' => true, 'mutating' => true, 'requires_approval' => true]),
            'node.run' => self::entry('Node ausführen', 'client', 'code', true, ['auto_dispatch' => true, 'mutating' => true, 'requires_approval' => true]),
            'agent.dispatch' => self::entry('Coding-Agent beauftragen', 'client', 'agent', true, [
                'auto_dispatch' => true, 'mutating' => true, 'requires_approval' => true,
                'params' => ['agent' => ['type' => 'string'], 'prompt' => ['type' => 'textarea']],
            ]),
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function entry(string $label, string $runner, string $kind, bool $allowedInDefinition, array $overrides = []): array
    {
        return array_merge([
            'label' => $label,
            'runner' => $runner,          // server | client
            'kind' => $kind,              // data|ai|control|device|memory|browser|file|api|code|agent
            'allowed_in_definition' => $allowedInDefinition,
            'auto_dispatch' => false,     // advance() may hand the step to the executor
            'mutating' => false,
            'requires_approval' => false,
            'timeout_seconds' => 300,
            'params' => [],
        ], $overrides);
    }

    /** Full definition for a task key, or null when it is not in the catalog. */
    public static function task(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** True only for tasks a workflow definition may currently reference. */
    public static function isAllowedInDefinition(string $key): bool
    {
        return (bool) (self::task($key)['allowed_in_definition'] ?? false);
    }

    /** True when advance() may dispatch the step to the executor unattended. */
    public static function isAutoDispatch(string $key): bool
    {
        return (bool) (self::task($key)['auto_dispatch'] ?? false);
    }

    /** True when the task runs on the device as a device_job bundle (P15b). */
    public static function isClientTask(string $key): bool
    {
        $task = self::task($key);

        return $task !== null && $task['runner'] === 'client' && $key !== 'device_job';
    }

    /**
     * Library view for UI / AI planning (every vetted task, with its contract).
     * @return array<int,array<string,mixed>>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $key => $def) {
            $out[] = [
                'key' => $key,
                'label' => $def['label'],
                'kind' => $def['kind'],
                'runner' => $def['runner'],
                'mutating' => $def['mutating'],
                'requires_approval' => $def['requires_approval'],
                'allowed_in_definition' => $def['allowed_in_definition'],
                'params' => $def['params'],
            ];
        }

        return $out;
    }
}
