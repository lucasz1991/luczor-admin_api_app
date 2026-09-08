<?php

namespace App\Services;

use App\Models\WorkflowStep;

/** Small data-only reference language: no evaluation, code or permissions in bindings. */
class WorkflowBindings
{
    private const IDENTITY_FIELDS = ['device_id', 'workflow_definition_id', 'file_scope', 'workspace_root_id', 'project_id'];

    public function resolve(WorkflowStep $step): array
    {
        if (is_array($step->resolved_payload)) {
            return $step->resolved_payload;
        }
        $run = $step->run;
        $outputs = $run->steps()->where('status', 'completed')->get()->mapWithKeys(fn ($item) => [$item->step_key => $item->output ?? []])->all();
        $scope = ['input' => $run->input ?? [], 'event' => $run->context['_execution']['event'] ?? [], 'steps' => $outputs];
        $payload = $step->payload ?? [];
        $bindings = $payload['input_bindings'] ?? [];
        unset($payload['input_bindings']);
        $payload = $this->walk($payload, $scope);
        foreach ($bindings as $target => $reference) {
            abort_if(in_array(explode('.', $target)[0], self::IDENTITY_FIELDS, true), 422, 'Workflow bindings cannot change execution identity.');
            data_set($payload, $target, $this->reference($reference, $scope));
        }
        abort_if(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 20000, 422, 'Resolved workflow payload is too large.');
        $step->update(['resolved_payload' => $payload]);

        return $payload;
    }

    private function walk(mixed $value, array $scope, ?string $key = null): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (isset($value['$ref']) && count($value) === 1) {
            abort_if(in_array($key, self::IDENTITY_FIELDS, true), 422, 'Workflow bindings cannot change execution identity.');

            return $this->reference($value['$ref'], $scope);
        }
        foreach ($value as $childKey => $child) {
            $value[$childKey] = $this->walk($child, $scope, (string) $childKey);
        }

        return $value;
    }

    private function reference(mixed $reference, array $scope): mixed
    {
        abort_unless(is_string($reference) && preg_match('/^(input|event|steps)(\.[A-Za-z0-9_-]+)+$/', $reference), 422, 'Invalid workflow data reference.');
        $missing = new \stdClass;
        $value = data_get($scope, $reference, $missing);
        abort_if($value === $missing, 422, 'Workflow data reference is unavailable: '.$reference);

        return $value;
    }
}
