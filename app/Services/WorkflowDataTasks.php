<?php

namespace App\Services;

use App\Models\DeviceJob;
use App\Models\WorkflowStep;

/** Data operations and assertions never evaluate expressions, PHP or JavaScript. */
class WorkflowDataTasks
{
    public function execute(WorkflowStep $step): array
    {
        $p = $step->resolved_payload ?? $step->payload ?? [];
        if ($step->type === 'control.join') {
            $outputs = $step->run->steps()->whereIn('step_key', $step->depends_on ?? [])->where('status', 'completed')->get()->mapWithKeys(fn ($s) => [$s->step_key => $s->output])->all();

            return ['outcome' => 'success', 'data' => $outputs];
        }
        if ($step->type === 'test.assert') {
            foreach ($p['assertions'] ?? [] as $assertion) {
                if (in_array($assertion['kind'] ?? '', ['file', 'browser'], true) && ! $step->run->sandbox) {
                    $raw = $step->payload['value']['$ref'] ?? $step->payload['input_bindings']['value'] ?? null;
                    $keys = $step->run->steps()->where('status', 'completed')->pluck('step_key')->all();
                    $key = is_string($raw) ? WorkflowBindings::stepReferenceKey($raw, $keys) : null;
                    $source = $key ? $step->run->steps()->where('step_key', $key)->first() : null;
                    abort_unless($source && str_starts_with($source->type, $assertion['kind'].'.')
                        && DeviceJob::where('public_id', $source->external_run_id)->where('workflow_execution_id', $source->execution_id)->where('status', 'completed')->exists(), 422, 'workflow_assertion_real_provenance_missing');
                }
            }

            return ['outcome' => 'success', 'data' => $this->assertions($p['value'] ?? null, $p['assertions'] ?? [])];
        }
        $items = $p['items'] ?? null;
        abort_unless(is_array($items) && array_is_list($items) && count($items) <= 1000, 422, 'workflow_data_items_invalid');
        $data = match ($step->type) {
            'data.map' => array_map(fn ($item) => $this->map($item, $p['mapping'] ?? []), $items),
            'data.filter' => array_values(array_filter($items, fn ($item) => $this->condition($item, $p['condition'] ?? []))),
            'data.split' => array_chunk($items, max(1, min(1000, (int) ($p['size'] ?? 1)))),
            'data.collect' => array_reduce($items, fn ($all, $item) => array_merge($all, is_array($item) && array_is_list($item) ? $item : [$item]), []),
            'data.merge' => $this->merge($items),
            default => abort(422, 'workflow_data_type_invalid'),
        };
        abort_if(strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 200000, 422, 'workflow_data_result_too_large');

        return ['outcome' => 'success', 'data' => $data];
    }

    private function map(mixed $item, array $mapping): array
    {
        abort_if(count($mapping) > 100, 422, 'workflow_mapping_too_large');
        $result = [];
        foreach ($mapping as $target => $source) {
            abort_unless(is_string($target) && preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $target), 422, 'workflow_mapping_key_invalid');
            $result[$target] = is_array($source) && array_keys($source) === ['path'] ? $this->path($item, $source['path']) : $source;
        }

        return $result;
    }

    private function merge(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            abort_unless(is_array($item) && ($item === [] || ! array_is_list($item)), 422, 'workflow_merge_requires_objects');
            foreach ($item as $key => $value) {
                abort_if(array_key_exists($key, $result) && $result[$key] !== $value, 422, 'workflow_merge_conflicting_key');
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function condition(mixed $value, array $condition): bool
    {
        $actual = array_key_exists('path', $condition) ? $this->path($value, $condition['path'], false) : $value;
        $expected = $condition['value'] ?? null;

        return match ($condition['operator'] ?? 'eq') {
            'eq' => $actual === $expected, 'neq' => $actual !== $expected,
            'exists' => $actual !== null,
            'gt', 'gte', 'lt', 'lte' => $this->compare($actual, $expected, $condition['operator']),
            'contains' => is_array($actual) ? in_array($expected, $actual, true) : (is_string($actual) && is_string($expected) && str_contains($actual, $expected)),
            default => abort(422, 'workflow_condition_invalid'),
        };
    }

    private function compare(mixed $left, mixed $right, string $operator): bool
    {
        abort_unless((is_int($left) || is_float($left)) && (is_int($right) || is_float($right)), 422, 'workflow_comparison_requires_numbers');

        return match ($operator) {
            'gt' => $left > $right, 'gte' => $left >= $right, 'lt' => $left < $right, 'lte' => $left <= $right,
            default => abort(422, 'workflow_comparison_operator_invalid'),
        };
    }

    public function assertions(mixed $value, array $assertions): array
    {
        $this->validateAssertions($assertions);
        $checks = [];
        foreach ($assertions as $index => $assertion) {
            abort_unless(is_array($assertion), 422, 'workflow_assertion_invalid');
            $kind = $assertion['kind'] ?? 'value';
            if ($kind === 'schema') {
                WorkflowSchema::validate(isset($assertion['path']) ? $this->path($value, $assertion['path']) : $value, $assertion['schema'] ?? []);
            } else {
                abort_unless(in_array($kind, ['value', 'file', 'browser', 'result'], true), 422, 'workflow_assertion_kind_invalid');
                if (in_array($kind, ['file', 'browser'], true)) {
                    // Such assertions consume actual adapter evidence, never inspect the server filesystem.
                    $evidence = $this->path($value, $assertion['evidence_path'] ?? '', false);
                    abort_unless(is_array($evidence) && ($evidence['kind'] ?? null) === $kind && ($evidence['verified'] ?? null) === true, 422, 'workflow_assertion_device_evidence_missing');
                }
                abort_unless($this->condition($value, $assertion), 422, 'workflow_assertion_failed:'.$index);
            }
            $checks[] = ['index' => $index, 'kind' => $kind, 'passed' => true];
        }

        return ['passed' => true, 'checks' => $checks];
    }

    public function validateAssertions(array $assertions): void
    {
        abort_unless(count($assertions) > 0 && count($assertions) <= 100, 422, 'workflow_assertions_required');
        foreach ($assertions as $assertion) {
            abort_unless(is_array($assertion), 422, 'workflow_assertion_invalid');
            $kind = $assertion['kind'] ?? 'value';
            abort_unless(in_array($kind, ['value', 'schema', 'file', 'browser', 'result'], true), 422, 'workflow_assertion_kind_invalid');
            if ($kind === 'schema') {
                abort_unless(is_array($assertion['schema'] ?? null) && isset($assertion['schema']['type']), 422, 'workflow_assertion_schema_required');
                WorkflowSchema::supported($assertion['schema']);
            } else {
                abort_unless(in_array($assertion['operator'] ?? 'eq', ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'exists'], true), 422, 'workflow_assertion_operator_invalid');
                abort_unless(($assertion['operator'] ?? '') === 'exists' || array_key_exists('value', $assertion), 422, 'workflow_assertion_expected_value_required');
            }
        }
    }

    private function path(mixed $value, mixed $path, bool $required = true): mixed
    {
        abort_unless(is_string($path) && ($path === '' || preg_match('/^[A-Za-z0-9_.-]{1,250}$/', $path)), 422, 'workflow_data_path_invalid');
        if ($path === '') {
            return $value;
        }
        $missing = new \stdClass;
        $result = data_get($value, $path, $missing);
        abort_if($required && $result === $missing, 422, 'workflow_data_path_missing');

        return $result === $missing ? null : $result;
    }
}
