<?php

namespace App\Services;

class WorkflowDefinitionValidator
{
    /** @return array<int,array{key:string,type:string,depends_on:array<int,string>,requires_approval:bool,max_attempts:int,payload:array<string,mixed>}> */
    public function validate(array $definition): array
    {
        abort_unless(strlen(json_encode($definition, JSON_THROW_ON_ERROR)) <= 200000, 422, 'Workflow definition is too large.');
        $steps = $definition['steps'] ?? [];
        abort_unless(is_array($steps) && count($steps) > 0 && count($steps) <= 100, 422, 'A workflow requires between 1 and 100 steps.');
        $keys = [];
        $normalized = [];
        foreach ($steps as $step) {
            abort_unless(is_array($step), 422, 'Invalid workflow step.');
            $key = trim((string) ($step['key'] ?? ''));
            $type = trim((string) ($step['type'] ?? ''));
            abort_unless($key !== '' && preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $key), 422, 'Invalid workflow step key.');
            abort_unless(WorkflowTaskCatalog::isAllowedInDefinition($type), 422, 'Invalid workflow step type.');
            abort_unless(! isset($keys[$key]), 422, 'Workflow step keys must be unique.');
            $keys[$key] = true;
            $payload = is_array($step['payload'] ?? null) ? $step['payload'] : [];
            $this->validatePayload($type, $payload);
            $routes = $this->normalizeRoutes($step['routes'] ?? []);
            if ($routes !== []) {
                $payload['routes'] = $routes;
            }
            $normalized[] = [
                'key' => $key,
                'type' => $type,
                'depends_on' => array_values(array_filter((array) ($step['depends_on'] ?? []), 'is_string')),
                'requires_approval' => (bool) ($step['requires_approval'] ?? false)
                    || (bool) (WorkflowTaskCatalog::task($type)['requires_approval'] ?? false),
                'max_attempts' => max(1, min(10, (int) ($step['max_attempts'] ?? 2))),
                'payload' => $payload,
            ];
        }

        foreach ($normalized as $step) {
            foreach ($step['depends_on'] as $dependency) {
                abort_unless(isset($keys[$dependency]) && $dependency !== $step['key'], 422, 'Workflow dependency does not exist.');
            }
            foreach ($step['payload']['routes'] ?? [] as $route) {
                if (($route['type'] ?? '') === 'step') {
                    abort_unless(isset($keys[$route['step_key']]), 422, 'Workflow route target does not exist.');
                }
            }
            if ($step['type'] === 'workflow') {
                abort_unless((int) ($step['payload']['workflow_definition_id'] ?? 0) > 0, 422, 'A workflow step requires payload.workflow_definition_id.');
            }
        }

        $dependencies = array_column($normalized, 'depends_on', 'key');
        $visited = [];
        $visiting = [];
        $visit = function (string $key) use (&$visit, &$visited, &$visiting, $dependencies): void {
            abort_if(isset($visiting[$key]), 422, 'Workflow dependency cycle detected.');
            if (isset($visited[$key])) {
                return;
            }
            $visiting[$key] = true;
            foreach ($dependencies[$key] as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$key]);
            $visited[$key] = true;
        };
        foreach (array_keys($dependencies) as $key) {
            $visit($key);
        }

        return $normalized;
    }

    /** @return array<string,array<string,mixed>> */
    private function normalizeRoutes(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }
        $allowedOutcomes = ['success', 'failed', 'partial', 'timeout', 'default', 'true', 'false'];
        $routes = [];
        foreach ($raw as $outcome => $route) {
            abort_unless(in_array($outcome, $allowedOutcomes, true), 422, 'Invalid workflow route outcome.');
            abort_unless(is_array($route), 422, 'Invalid workflow route.');
            $type = (string) ($route['type'] ?? '');
            abort_unless(in_array($type, ['end', 'fail', 'step'], true), 422, 'Invalid workflow route type.');
            $entry = ['type' => $type];
            if ($type === 'step') {
                $target = trim((string) ($route['step_key'] ?? ''));
                abort_unless($target !== '', 422, 'Workflow route step target is required.');
                $entry['step_key'] = $target;
                $entry['max_iterations'] = max(1, min(50, (int) ($route['max_iterations'] ?? 2)));
            }
            $routes[$outcome] = $entry;
        }

        return $routes;
    }

    public function validatePayload(string $type, array $payload): void
    {
        abort_unless(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) <= 20000, 422, 'Workflow step payload is too large.');
        $bindings = $payload['input_bindings'] ?? [];
        abort_unless(is_array($bindings) && count($bindings) <= 50, 422, 'Invalid input bindings.');
        foreach ($bindings as $target => $reference) {
            abort_unless(is_string($target) && preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $target)
                && is_string($reference) && preg_match('/^(input|event|steps)(\.[A-Za-z0-9_-]+)+$/', $reference), 422, 'Invalid input binding.');
            abort_if(in_array(explode('.', $target)[0], ['device_id', 'workflow_definition_id', 'project_id', 'file_scope', 'workspace_root_id'], true), 422, 'Bindings cannot change execution identity.');
        }
        if ($type === 'condition') {
            abort_unless(in_array($payload['operator'] ?? 'eq', ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'exists'], true), 422, 'Invalid condition operator.');
        }
        if ($type === 'llm') {
            abort_unless(in_array($payload['output_format'] ?? 'text', ['text', 'json'], true), 422, 'Invalid LLM output format.');
            abort_unless(($payload['inference'] ?? 'local') === 'local', 422, 'Workflow LLM execution currently requires local inference.');
        }
        if (isset($payload['file_scope'])) {
            abort_unless(in_array($payload['file_scope'], ['legacy', 'workspace'], true), 422, 'Invalid file scope.');
            abort_if($payload['file_scope'] === 'workspace' && ! is_string($payload['workspace_root_id'] ?? null), 422, 'Workspace file access requires an explicit root binding.');
        }
    }
}
