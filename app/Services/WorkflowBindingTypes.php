<?php

namespace App\Services;

/** Static type evidence only. Every resolved value still passes the runtime schema check. */
class WorkflowBindingTypes
{
    public function inspect(array $definition, array $steps): array
    {
        if (($definition['schema_version'] ?? 1) !== 2) {
            return [];
        }
        $byKey = array_column($steps, null, 'key');
        $result = [];
        foreach ($steps as $step) {
            $payload = $step['payload'];
            $bindings = $payload['input_bindings'] ?? [];
            unset($payload['input_bindings']);
            if (str_starts_with($step['type'], 'control.')) {
                unset($payload['body'], $payload['branches']);
            }
            $scan = function (mixed $value, string $path = '') use (&$scan, &$bindings): void {
                if (! is_array($value)) {
                    return;
                }
                if (array_keys($value) === ['$ref']) {
                    // Explicit input_bindings are applied after inline references at runtime.
                    $bindings[$path] ??= $value['$ref'];

                    return;
                }
                foreach ($value as $key => $child) {
                    $scan($child, $path === '' ? (string) $key : $path.'.'.$key);
                }
            };
            $scan($payload);
            foreach ($bindings as $target => $reference) {
                $source = $this->source($reference, $definition, $byKey);
                $destination = $this->at(WorkflowTaskCatalog::task($step['type'])['input_schema'], explode('.', $target));
                abort_if($source === null || $destination === null, 422, 'workflow_binding_schema_path_invalid:'.$step['key'].'.'.$target);
                $compatible = $this->compatible($source, $destination);
                abort_if($compatible === false, 422, 'workflow_binding_type_mismatch:'.$step['key'].'.'.$target);
                $result[] = ['step_key' => $step['key'], 'target' => $target, 'source' => $reference,
                    'status' => $compatible === null ? 'runtime_required' : 'types_compatible',
                    'source_type' => $source['type'] ?? null, 'target_type' => $destination['type'] ?? null,
                    'runtime_validation_required' => true];
            }
        }

        return $result;
    }

    private function source(string $reference, array $definition, array $steps): ?array
    {
        if (str_starts_with($reference, 'input.')) {
            return $this->at($definition['input_schema'] ?? [], explode('.', substr($reference, 6)));
        }
        if (str_starts_with($reference, 'event.')) {
            // Events depend on the actual trigger. No catalog declaration proves their payload shape.
            return [];
        }
        $key = WorkflowBindings::stepReferenceKey($reference, array_keys($steps));
        if ($key === null) {
            return null;
        }
        $step = $steps[$key];
        $schema = WorkflowTaskCatalog::task($step['type'])['output_schema'];
        $path = explode('.', substr($reference, strlen('steps.'.$key.'.')));
        $custom = $step['payload']['output_schema'] ?? null;
        if (is_array($custom) && $path[0] === 'data') {
            // complete() validates the declared result schema on data (when present).
            return $this->at($custom, array_slice($path, 1));
        }

        return $this->at($schema, $path);
    }

    /** null means an impossible path; [] means the catalog cannot establish its type. */
    private function at(array $schema, array $segments): ?array
    {
        if ($segments === []) {
            return $schema;
        }
        $key = array_shift($segments);
        if (($schema['type'] ?? null) === 'array') {
            if (! ctype_digit((string) $key)) {
                return null;
            }

            return $this->at($schema['items'] ?? [], $segments);
        }
        if (isset($schema['properties'][$key])) {
            return $this->at($schema['properties'][$key], $segments);
        }
        if (isset($schema['type']) && $schema['type'] !== 'object') {
            return null;
        }

        return ($schema['additionalProperties'] ?? true) === false ? null : [];
    }

    /** Constraint ranges, optional fields and unknown subtrees are always checked after resolution. */
    private function compatible(array $source, array $target): ?bool
    {
        $from = $source['type'] ?? null;
        $to = $target['type'] ?? null;
        if ($from === null || $to === null) {
            return null;
        }
        if ($from !== $to && ! ($from === 'integer' && $to === 'number')) {
            return false;
        }
        if ($from === 'array') {
            return $this->compatible($source['items'] ?? [], $target['items'] ?? []);
        }
        if ($from === 'object') {
            $unknown = false;
            foreach ($target['properties'] ?? [] as $key => $child) {
                if (! isset($source['properties'][$key])) {
                    $unknown = true;

                    continue;
                }
                $check = $this->compatible($source['properties'][$key], $child);
                if ($check === false) {
                    return false;
                }
                $unknown = $unknown || $check === null;
            }

            return $unknown ? null : true;
        }

        return true;
    }
}
