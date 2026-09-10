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
        $ui = self::uiMetadata($key, $task, $group, $client);

        return [
            'type' => $key, 'version' => 1,
            'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => true],
            'output_schema' => self::output($key),
            'required_capabilities' => $client ? [$key.':1'] : [],
            'adapters' => [$adapter], 'execution_location' => $client ? 'device' : 'server',
            ...$ui,
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

    /**
     * Stable, additive metadata used by the Tool Center. These fields describe
     * presentation and session semantics only; execution remains governed by
     * the existing runner, capability and approval contracts above.
     */
    private static function uiMetadata(string $key, array $task, string $group, bool $client): array
    {
        $capabilityGroup = match ($group) {
            'browser' => 'browser',
            'image' => 'vision',
            'code', 'node', 'python' => 'terminal',
            'llm', 'agent' => 'model',
            default => $group,
        };
        $sessionKind = match ($capabilityGroup) {
            'browser', 'vision', 'terminal', 'model' => $capabilityGroup,
            default => null,
        };

        return [
            'capability_group' => $capabilityGroup,
            'result_handling' => $client || in_array($group, ['llm', 'agent', 'image', 'browser', 'code'], true)
                ? 'ephemeral'
                : 'syncable',
            'session_kind' => $sessionKind,
            'approval_mode' => ($task['requires_approval'] ?? false) || ($task['mutating'] ?? false)
                ? 'session'
                : 'call',
            'ui_statuses' => ['unavailable', 'ready', 'approval_open', 'running', 'waiting', 'succeeded', 'failed', 'aborted'],
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
                'monitor_id' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4294967295],
                'max_chars' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000]];
            if ($key !== 'image.capture') {
                $required[] = 'artifact_id';
            }
            if ($key === 'image.compare') {
                $required[] = 'other_artifact_id';
            }
            if ($key === 'image.vision') {
                $props += ['instruction' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000],
                    'inference' => ['type' => 'string', 'enum' => ['local', 'external']],
                    'output_format' => ['type' => 'string', 'enum' => ['text', 'json']],
                    'max_output_chars' => ['type' => 'integer', 'minimum' => 256, 'maximum' => 20000]];
                $required[] = 'instruction';
                $required[] = 'inference';
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
            $props['environment'] = ['type' => 'object', 'additionalProperties' => false,
                'required' => ['version', 'runtime_version', 'dependencies'], 'properties' => [
                    'version' => ['type' => 'integer', 'enum' => [1]],
                    'runtime_version' => ['type' => 'string'],
                    'dependencies' => ['type' => 'array', 'maxItems' => 64, 'items' => ['type' => 'object', 'additionalProperties' => false,
                        'required' => ['name', 'version'], 'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160], 'version' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 60]]]],
                    'lock_path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                    'lock_sha256' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                ]];
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

        // Optional fields describe the public runner output without tightening V1 presence rules.
        // Nullable/dynamic values stay open in the existing bounded JSON Schema subset.
        $nullableString = ['description' => 'String or null; the value must be checked at runtime.'];
        $nullableInteger = ['description' => 'Integer or null; the value must be checked at runtime.'];
        $dynamic = ['description' => 'Dynamic JSON value; the value must be checked at runtime.'];
        $artifact = ['type' => 'object', 'additionalProperties' => true, 'properties' => [
            'artifactId' => ['type' => 'string'], 'mime' => ['type' => 'string'],
            'bytes' => ['type' => 'integer'], 'sha256' => ['type' => 'string'],
            'name' => ['type' => 'string'], 'width' => $nullableInteger, 'height' => $nullableInteger,
        ]];
        if (str_starts_with($key, 'browser.')) {
            $schema['properties'] = [
                'ok' => ['type' => 'boolean'], 'sessionId' => ['type' => 'string'],
                'tabId' => ['type' => 'string'], 'url' => ['type' => 'string'],
            ];
            if ($key === 'browser.read') {
                // browser.ts intentionally lifts native data.text into the public text field.
                $schema['properties'] += ['text' => ['type' => 'string'], 'truncated' => ['type' => 'boolean']];
            } elseif (in_array($key, ['browser.screenshot', 'browser.download'], true)) {
                $schema['properties']['data'] = $artifact;
            } else {
                $data = match ($key) {
                    'browser.open', 'browser.open_url', 'browser.navigate' => ['opened' => ['type' => 'boolean'], 'readiness' => ['type' => 'string']],
                    'browser.click' => ['ok' => ['type' => 'boolean'], 'clicked' => ['type' => 'boolean']],
                    'browser.fill', 'browser.select' => ['ok' => ['type' => 'boolean'], 'applied' => ['type' => 'boolean']],
                    'browser.wait' => ['ok' => ['type' => 'boolean'], 'ready' => ['type' => 'boolean']],
                    default => [],
                };
                $schema['properties']['data'] = ['type' => 'object', 'additionalProperties' => true, 'properties' => $data];
                if ($key === 'browser.open_url') {
                    $schema['properties']['opened'] = ['type' => 'string'];
                }
                if ($key === 'browser.click') {
                    $schema['properties']['clicked'] = ['type' => 'string'];
                }
            }
        }
        if ($key === 'image.capture') {
            // capture returns the artifact itself, not an {ok, artifact} envelope.
            $schema['properties'] = $artifact['properties'];
        }
        if ($key === 'image.ocr') {
            $schema['properties'] = [
                'ok' => ['type' => 'boolean'], 'text' => ['type' => 'string'],
                'truncated' => ['type' => 'boolean'], 'language' => ['type' => 'string'], 'method' => ['type' => 'string'],
            ];
        }
        if ($key === 'image.compare') {
            $schema['properties'] = [
                'ok' => ['type' => 'boolean'], 'identical' => ['type' => 'boolean'],
                'sameDimensions' => ['type' => 'boolean'], 'changedPixels' => ['type' => 'integer'],
                'totalPixels' => ['type' => 'integer'], 'differentFraction' => ['type' => 'number'], 'method' => ['type' => 'string'],
            ];
        }
        if ($key === 'image.vision') {
            $schema['properties'] = [
                'ok' => ['type' => 'boolean'], 'outcome' => ['type' => 'string'],
                'text' => ['type' => 'string'], 'data' => ['type' => 'object'],
                'model' => ['type' => 'string'], 'provider' => ['type' => 'string'],
                'request_id' => ['type' => 'string'], 'finish_reason' => ['type' => 'string'],
                'inference_target' => ['type' => 'string'], 'thinking_application' => ['type' => 'string'],
                'policy_revision' => ['type' => 'string'], 'artifact_sha256' => ['type' => 'string'],
                'usage_source' => ['type' => 'string'], 'usage' => ['type' => 'object', 'properties' => array_fill_keys(
                    ['input_tokens', 'output_tokens', 'total_tokens', 'prompt_tokens', 'completion_tokens'], ['type' => 'integer']
                )],
            ];
        }
        if (in_array($key, ['node.run', 'python.run'], true)) {
            $schema['properties'] = [
                'ok' => ['type' => 'boolean'], 'code' => ['type' => 'integer'],
                'stdout' => ['type' => 'string'], 'stderr' => ['type' => 'string'],
                'timed_out' => ['type' => 'boolean'], 'duration_ms' => ['type' => 'integer'],
                'runtime' => ['type' => 'string'], 'interpreter' => ['type' => 'string'],
                'runtime_version' => $nullableString, 'execution_profile' => ['type' => 'string'],
                'input_mode' => ['type' => 'string'], 'code_sha256' => ['type' => 'string'],
                'execution_environment' => ['type' => 'string'], 'data' => $dynamic,
                'environment' => ['type' => 'object', 'properties' => [
                    'revision' => ['type' => 'string'], 'lock_sha256' => $nullableString,
                    'dependency_count' => ['type' => 'integer'], 'reused' => ['type' => 'boolean'],
                    'installed_sha256' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                ]],
            ];
        }
        if (in_array($key, ['agent.single', 'agent.team'], true)) {
            $schema['properties'] = [
                'ok' => ['type' => 'boolean'], 'outcome' => ['type' => 'string'], 'text' => ['type' => 'string'],
                'code' => ['type' => 'string'], 'agent' => ['type' => 'string'], 'model' => $nullableString,
                'requested_model' => $nullableString, 'inference_target' => $nullableString,
                'request_id' => $nullableString, 'thinking_tier' => ['type' => 'string'],
                'thinking_application' => ['type' => 'string'], 'selection_reason' => ['type' => 'string'],
                'duration_ms' => ['type' => 'integer'], 'continuation_available' => ['type' => 'boolean'],
                'interruption_code' => $nullableString, 'data' => $dynamic,
            ];
        }
        if ($key === 'agent.dispatch') {
            $schema['properties'] = [
                'ok' => ['type' => 'boolean'], 'code' => ['type' => 'integer'],
                'stdout' => ['type' => 'string'], 'stderr' => ['type' => 'string'],
            ];
        }

        return $schema;
    }
}
