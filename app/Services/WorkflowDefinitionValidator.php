<?php

namespace App\Services;

class WorkflowDefinitionValidator
{
    /** @return array<int,array{key:string,type:string,version:int,depends_on:array<int,string>,requires_approval:bool,max_attempts:int,payload:array<string,mixed>}> */
    public function validate(array $definition, int $depth = 0): array
    {
        abort_if($depth > 8, 422, 'Workflow control nesting exceeds eight levels.');
        abort_unless(in_array($definition['schema_version'] ?? 1, [1, 2], true), 422, 'Unsupported workflow schema version.');
        WorkflowBudgetService::policy($definition);
        abort_unless(in_array($definition['thinking_tier'] ?? 'balanced', ['fast', 'balanced', 'thorough', 'max', 'ultra'], true), 422, 'Invalid workflow thinking tier.');
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
            abort_if(isset(WorkflowTaskContracts::additions()[$type]) && ($definition['schema_version'] ?? 1) !== 2, 422, 'This workflow task requires schema version 2.');
            abort_unless(($step['version'] ?? 1) === 1, 422, 'Unsupported workflow task version.');
            abort_unless(! isset($keys[$key]), 422, 'Workflow step keys must be unique.');
            $keys[$key] = true;
            $payload = is_array($step['payload'] ?? null) ? $step['payload'] : [];
            $this->validatePayload($type, $payload);
            if (($definition['schema_version'] ?? 1) === 2) {
                $boundInput = $payload;
                foreach ($payload['input_bindings'] ?? [] as $target => $reference) {
                    data_set($boundInput, $target, ['$ref' => $reference]);
                }
                WorkflowSchema::validate($boundInput, WorkflowTaskCatalog::task($type)['input_schema'], '$.'.$key, true);
            }
            if (in_array($type, ['control.foreach', 'control.until'], true)) {
                abort_unless(is_array($payload['body'] ?? null), 422, 'Workflow control body is required.');
                $this->validate(array_merge($payload['body'], ['schema_version' => 2]), $depth + 1);
            }
            if ($type === 'control.parallel') {
                abort_unless(is_array($payload['branches'] ?? null) && count($payload['branches']) >= 1 && count($payload['branches']) <= 10, 422, 'Invalid parallel branches.');
                foreach ($payload['branches'] as $branch) {
                    abort_unless(is_array($branch), 422, 'Invalid parallel branch.');
                    $this->validate(array_merge($branch, ['schema_version' => 2]), $depth + 1);
                }
            }
            $routes = $this->normalizeRoutes($step['routes'] ?? []);
            if ($routes !== []) {
                $payload['routes'] = $routes;
            }
            $normalized[] = [
                'key' => $key,
                'type' => $type,
                'version' => $step['version'] ?? 1,
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
        foreach ($normalized as $step) {
            $references = array_values($step['payload']['input_bindings'] ?? []);
            $scan = function (mixed $value) use (&$scan, &$references): void {
                if (! is_array($value)) {
                    return;
                }
                if (array_key_exists('$ref', $value)) {
                    abort_unless(count($value) === 1 && is_string($value['$ref']), 422, 'Invalid data reference object.');
                    $references[] = $value['$ref'];
                }
                foreach ($value as $child) {
                    $scan($child);
                }
            };
            $referencePayload = $step['payload'];
            if (str_starts_with($step['type'], 'control.')) {
                unset($referencePayload['body'], $referencePayload['branches']);
            }
            $scan($referencePayload);
            $predecessors = $step['depends_on'];
            for ($index = 0; $index < count($predecessors); $index++) {
                foreach ($dependencies[$predecessors[$index]] ?? [] as $ancestor) {
                    if (! in_array($ancestor, $predecessors, true)) {
                        $predecessors[] = $ancestor;
                    }
                }
            }
            foreach ($references as $reference) {
                abort_unless(preg_match('/^(input|event|steps)(\.[A-Za-z0-9_.-]+)+$/', $reference), 422, 'Invalid workflow data reference.');
                if (str_starts_with($reference, 'steps.')) {
                    $sourceKey = WorkflowBindings::stepReferenceKey($reference, array_keys($keys));
                    abort_unless($sourceKey !== null && in_array($sourceKey, $predecessors, true), 422, 'Step binding must reference a declared predecessor.');
                }
            }
        }

        return $normalized;
    }

    /** @return array<string,array<string,mixed>> */
    private function normalizeRoutes(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }
        $allowedOutcomes = ['success', 'failed', 'partial', 'timeout', 'cancelled', 'default', 'true', 'false'];
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
        if (isset($payload['output_schema'])) {
            abort_unless(is_array($payload['output_schema']), 422, 'Invalid workflow output schema.');
            WorkflowSchema::supported($payload['output_schema']);
        }
        if ($type === 'test.assert') {
            abort_unless(is_array($payload['assertions'] ?? null), 422, 'workflow_assertions_required');
            app(WorkflowDataTasks::class)->validateAssertions($payload['assertions']);
        }
        if (array_key_exists('thinking_tier', $payload)) {
            abort_unless(in_array($payload['thinking_tier'], ['inherit', 'fast', 'balanced', 'thorough', 'max', 'ultra'], true), 422, 'Invalid workflow task thinking tier.');
        }
        if (array_key_exists('thinking_config', $payload)) {
            $config = $payload['thinking_config'];
            abort_unless(is_array($config) && count($config) === 3 && array_diff(array_keys($config), ['initialTokens', 'maxThinkingTokens', 'responseReserveTokens']) === [], 422, 'Invalid workflow thinking config.');
            foreach ($config as $value) {
                abort_unless(is_int($value), 422, 'Invalid workflow thinking token count.');
            }
            abort_unless($config['initialTokens'] >= 1 && $config['initialTokens'] <= $config['maxThinkingTokens'] && $config['maxThinkingTokens'] <= 65536
                && $config['responseReserveTokens'] >= 256 && $config['maxThinkingTokens'] + $config['responseReserveTokens'] <= 131072, 422, 'Workflow thinking config exceeds bounds.');
        }
        abort_unless(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) <= 20000, 422, 'Workflow step payload is too large.');
        $bindings = $payload['input_bindings'] ?? [];
        abort_unless(is_array($bindings) && count($bindings) <= 50, 422, 'Invalid input bindings.');
        foreach ($bindings as $target => $reference) {
            abort_unless(is_string($target) && preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $target)
                && is_string($reference) && preg_match('/^(input|event|steps)(\.[A-Za-z0-9_-]+)+$/', $reference), 422, 'Invalid input binding.');
            abort_if(in_array(explode('.', $target)[0], ['device_id', 'workflow_definition_id', 'project_id', 'file_scope', 'workspace_root_id'], true), 422, 'Bindings cannot change execution identity.');
            abort_if(in_array(explode('.', $target)[0], ['body', 'branches'], true), 422, 'Bindings cannot replace control definitions.');
        }
        if ($type === 'condition') {
            abort_unless(in_array($payload['operator'] ?? 'eq', ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'exists'], true), 422, 'Invalid condition operator.');
        }
        if ($type === 'llm') {
            abort_unless(in_array($payload['output_format'] ?? 'text', ['text', 'json'], true), 422, 'Invalid LLM output format.');
            abort_unless(in_array($payload['inference'] ?? 'local', ['local', 'external'], true), 422, 'Invalid workflow inference target.');
        }
        if (isset($payload['file_scope'])) {
            abort_unless(in_array($payload['file_scope'], ['legacy', 'workspace'], true), 422, 'Invalid file scope.');
            abort_if($payload['file_scope'] === 'workspace' && ! is_string($payload['workspace_root_id'] ?? null), 422, 'Workspace file access requires an explicit root binding.');
        }
    }
}
