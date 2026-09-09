<?php

namespace App\Services;

/** Version 1 contracts are additive; changing their meaning requires a new version. */
class WorkflowTaskContracts
{
    public static function additions(): array
    {
        $tasks = [];
        $groups = [
            'browser' => ['navigate', 'fill', 'select', 'wait', 'screenshot', 'download'],
            'llm' => ['text', 'json', 'classify', 'extract', 'evaluate'],
            'image' => ['capture', 'ocr', 'vision', 'compare'],
            'agent' => ['single', 'team'],
            'data' => ['map', 'filter', 'split', 'collect', 'merge'],
            'control' => ['foreach', 'until', 'parallel', 'join'],
            'test' => ['assert'],
        ];
        foreach ($groups as $group => $operations) {
            foreach ($operations as $operation) {
                $server = in_array($group, ['data', 'control', 'test'], true);
                $mutating = ! $server && ! in_array($group, ['image', 'llm'], true);
                $tasks[$group.'.'.$operation] = [
                    'label' => ucfirst($group).' · '.$operation,
                    'kind' => $group, 'runner' => $server ? 'server' : 'client',
                    'auto_dispatch' => true, 'mutating' => $mutating,
                    'requires_approval' => $group === 'agent',
                ];
            }
        }

        return $tasks;
    }

    public static function describe(string $key, array $task): array
    {
        $properties = [];
        $required = [];
        foreach ($task['params'] ?? [] as $name => $field) {
            $properties[$name] = ['type' => match ($field['type'] ?? 'string') {
                'textarea' => 'string', 'number' => 'number', 'object' => 'object', default => 'string',
            }];
            foreach (['enum', 'default'] as $attribute) {
                if (isset($field[$attribute])) {
                    $properties[$name][$attribute] = $field[$attribute];
                }
            }
            if ($field['required'] ?? false) {
                $required[] = $name;
            }
        }
        $specific = self::fields($key);
        $properties = array_replace($properties, $specific['properties'] ?? []);
        $required = array_values(array_unique(array_merge($required, $specific['required'] ?? [])));
        $client = $task['runner'] === 'client';
        $group = explode('.', $key)[0];
        $adapter = match ($group) {
            'node', 'python' => 'windows.user.'.$group,
            'browser' => 'browser.session',
            'image' => 'desktop.image',
            'llm' => 'inference',
            'agent' => 'agent.orchestrator',
            default => $client ? 'desktop.tools' : 'laravel.scheduler',
        };

        return [
            'type' => $key, 'version' => 1,
            'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => true],
            'output_schema' => self::output($key),
            'required_capabilities' => $client ? [$key.':1'] : [],
            'adapters' => [$adapter], 'execution_location' => $client ? 'device' : 'server',
            'resource_keys' => match ($group) {
                'browser' => ['device', 'browser_session_id'], 'llm', 'agent' => ['device', 'local_inference'], default => [],
            },
            'outcomes' => ['success', 'failed', 'partial', 'timeout', 'cancelled'],
            'retry' => ['maximum_attempts' => 10, 'backoff' => 'exponential', 'execution_id' => 'stable_until_route_revisit', 'side_effects' => 'verify_before_retry',
                'terminal_device_failure' => 'repair_or_new_user_run_required', 'unconfirmed_device_stop' => 'cancel_and_wait_for_ack'],
            'cancel' => ['mode' => $client ? 'request_and_confirm_device_stop' : 'cooperative', 'terminal_after_confirmation' => true],
            'test' => ['modes' => ['definition', 'simulation', 'real'], 'fixtures_required_for_simulated_effects' => true, 'real_requires_capability' => $client],
        ];
    }

    private static function fields(string $key): array
    {
        $props = [];
        $required = [];
        if (str_starts_with($key, 'browser.')) {
            $props = ['browser_session_id' => ['type' => 'string'], 'tab_id' => ['type' => 'string'], 'selector' => ['type' => 'string'], 'url' => ['type' => 'string']];
            if (in_array($key, ['browser.fill', 'browser.select'], true)) {
                $props['value'] = ['type' => 'string'];
                $required = ['selector', 'value'];
            }
            if ($key === 'browser.navigate') {
                $required = ['url'];
            }
        }
        if (str_starts_with($key, 'llm.') || str_starts_with($key, 'agent.')) {
            $props += ['instruction' => ['type' => 'string'], 'agent_selection' => ['type' => 'string', 'enum' => ['auto', 'override']], 'model' => ['type' => 'string'], 'output_schema' => ['type' => 'object']];
        }
        if (str_starts_with($key, 'agent.')) {
            $props += ['agent' => ['type' => 'string', 'enum' => ['local', 'codex', 'claude']],
                'team_preset' => ['type' => 'string', 'enum' => ['server', 'local', 'free', 'budget']],
                'max_turns' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 64],
                'max_rounds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 64],
                'max_budget_usd' => ['type' => 'number', 'minimum' => 0.000001, 'maximum' => 1000],
                'input_bindings' => ['type' => 'object'], 'timeout_seconds' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 2700],
                'max_output_chars' => ['type' => 'integer', 'minimum' => 256, 'maximum' => 20000]];
            $props['instruction'] = ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000];
            $required[] = 'instruction';
        }
        if (str_starts_with($key, 'image.')) {
            $props = ['artifact_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'other_artifact_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'language' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40],
                'monitor_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'max_chars' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000]];
            if ($key !== 'image.capture') {
                $required[] = 'artifact_id';
            }
            if ($key === 'image.compare') {
                $required[] = 'other_artifact_id';
            }
        }
        if ($key === 'llm' || str_starts_with($key, 'llm.') || str_starts_with($key, 'agent.')) {
            $props['thinking_tier'] = ['type' => 'string', 'enum' => ['inherit', 'fast', 'balanced', 'thorough', 'max', 'ultra']];
            $props['thinking_config'] = ['type' => 'object', 'properties' => ['initialTokens' => ['type' => 'integer'], 'maxThinkingTokens' => ['type' => 'integer'], 'responseReserveTokens' => ['type' => 'integer']], 'required' => ['initialTokens', 'maxThinkingTokens', 'responseReserveTokens'], 'additionalProperties' => false];
        }
        if (str_starts_with($key, 'llm.')) {
            $props['instruction'] = ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000];
            $props['inference'] = ['type' => 'string', 'enum' => ['local', 'external']];
            $props['output_format'] = ['type' => 'string', 'enum' => ['text', 'json']];
            $props['labels'] = ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 100];
            $props['criteria'] = ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 100];
            $required[] = 'instruction';
            if ($key === 'llm.classify') {
                $required[] = 'labels';
            }
            if ($key === 'llm.evaluate') {
                $required[] = 'criteria';
            }
            if ($key === 'llm.extract') {
                $required[] = 'output_schema';
            }
        }
        if (in_array($key, ['node.run', 'python.run'], true)) {
            $props += ['input' => ['type' => 'object'], 'output_schema' => ['type' => 'object'], 'execution_environment' => ['type' => 'string', 'enum' => ['windows_user']]];
        }
        if (str_starts_with($key, 'data.')) {
            $props = ['items' => ['type' => 'array'], 'mapping' => ['type' => 'object'], 'condition' => ['type' => 'object'], 'size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000]];
            $required = ['items'];
        }
        if (in_array($key, ['control.foreach', 'control.until'], true)) {
            $props = ['body' => ['type' => 'object'], 'items' => ['type' => 'array'], 'condition' => ['type' => 'object'], 'max_iterations' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]];
            $required = $key === 'control.foreach' ? ['body', 'items'] : ['body', 'condition'];
        }
        if ($key === 'control.parallel') {
            $props = ['branches' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10]];
            $required = ['branches'];
        }
        if ($key === 'test.assert') {
            $props = ['assertions' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100], 'value' => []];
            $required = ['assertions'];
        }

        return ['properties' => $props, 'required' => $required];
    }

    private static function output(string $key): array
    {
        $schema = ['type' => 'object', 'additionalProperties' => true];
        if (str_starts_with($key, 'data.') || str_starts_with($key, 'control.') || $key === 'test.assert') {
            $schema += ['required' => ['outcome', 'data'], 'properties' => ['outcome' => ['type' => 'string', 'enum' => ['success']]]];
        }
        if ($key === 'llm.classify') {
            $schema += ['required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'required' => ['label'], 'properties' => ['label' => ['type' => 'string']]]]];
        }
        if ($key === 'llm.text') {
            $schema += ['required' => ['ok', 'text'], 'properties' => ['ok' => ['type' => 'boolean', 'enum' => [true]], 'text' => ['type' => 'string', 'minLength' => 1]]];
        }
        if (in_array($key, ['llm.json', 'llm.extract'], true)) {
            $schema += ['required' => ['ok', 'data'], 'properties' => ['ok' => ['type' => 'boolean', 'enum' => [true]]]];
        }
        if ($key === 'llm.evaluate') {
            $schema += ['required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'required' => ['passed', 'checks'], 'properties' => ['passed' => ['type' => 'boolean'], 'checks' => ['type' => 'array']]]]];
        }

        return $schema;
    }
}
