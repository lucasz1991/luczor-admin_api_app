<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Repository;
use App\Models\Task;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowTrigger;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WorkflowTriggerService
{
    public const KINDS = ['schedule', 'webhook', 'task.completed', 'workflow.completed', 'github.push', 'github.pull_request', 'workspace.file_changed'];

    public function save(WorkflowDefinition $definition, array $data, ?WorkflowTrigger $trigger = null): array
    {
        $config = $this->validateConfig($definition, $data['kind'], $data['config'] ?? []);
        $secret = null;
        $values = [
            'user_id' => $definition->user_id, 'workflow_definition_id' => $definition->id, 'project_id' => $definition->project_id,
            'name' => $data['name'], 'kind' => $data['kind'], 'enabled' => $data['enabled'] ?? false,
            'config' => $config, 'input' => $data['input'] ?? [], 'last_error' => null,
        ];
        if ($data['kind'] === 'schedule') {
            $values['next_due_at'] = $this->nextDue($config, now());
        } else {
            $values['next_due_at'] = null;
        }
        if (! $trigger) {
            $values['public_id'] = $data['operation_id'] ?? (string) Str::uuid();
            if ($existing = WorkflowTrigger::where('public_id', $values['public_id'])->first()) {
                abort_unless((int) $existing->workflow_definition_id === (int) $definition->id, 409, 'Trigger operation was already used.');

                return $existing->toArray();
            }
        }
        if ($data['kind'] === 'webhook' && (! $trigger || $trigger->kind !== 'webhook' || ($data['rotate_secret'] ?? false))) {
            $secret = Str::random(64);
            $values['secret_hash'] = hash('sha256', $secret);
        }
        $trigger ? $trigger->update($values) : $trigger = WorkflowTrigger::create($values);
        $result = $trigger->fresh()->toArray();
        if ($secret) {
            $result['webhook_secret'] = $secret;
        }

        return $result;
    }

    public function validateConfig(WorkflowDefinition $definition, string $kind, array $config): array
    {
        abort_unless(in_array($kind, self::KINDS, true), 422, 'Unsupported workflow trigger.');
        $rules = match ($kind) {
            'schedule' => ['cron' => 'nullable|string|max:100', 'run_at' => 'nullable|date', 'timezone' => 'required|timezone'],
            'webhook' => [],
            'task.completed' => ['task_id' => 'nullable|integer|min:1'],
            'workflow.completed' => ['workflow_definition_id' => 'nullable|integer|min:1', 'statuses' => 'sometimes|array|min:1|max:3', 'statuses.*' => 'in:completed,failed,cancelled'],
            'github.push', 'github.pull_request' => ['repository_id' => 'required|integer|min:1', 'branch' => 'nullable|string|max:250', 'actions' => 'sometimes|array|max:20', 'actions.*' => 'string|max:60'],
            'workspace.file_changed' => ['device_id' => 'required|string|max:120', 'root_path' => 'required|string|max:2000', 'paths' => 'required|array|min:1|max:32', 'paths.*' => 'string|max:500', 'excludes' => 'sometimes|array|max:32', 'excludes.*' => 'string|max:500', 'debounce_seconds' => 'sometimes|integer|min:1|max:60'],
        };
        $valid = Validator::make($config, $rules)->validate();
        $valid = array_filter($valid, fn ($value) => $value !== null);
        if ($kind === 'schedule') {
            abort_unless(isset($valid['cron']) xor isset($valid['run_at']), 422, 'Choose cron or a one-time timestamp.');
            if (isset($valid['cron'])) {
                abort_unless(count(preg_split('/\s+/', trim($valid['cron']))) === 5 && CronExpression::isValidExpression($valid['cron']), 422, 'A valid five-field cron expression is required.');
            } else {
                abort_unless(preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $valid['run_at']), 422, 'One-time timestamps require an explicit UTC offset.');
                $valid['run_at'] = Carbon::parse($valid['run_at'])->utc()->toISOString();
            }
        }
        if (isset($valid['task_id'])) {
            abort_unless(Task::whereKey($valid['task_id'])->where('user_id', $definition->user_id)->where('project_ref_id', $definition->project_id)->exists(), 422, 'Task source is outside the workflow project.');
        }
        if (isset($valid['workflow_definition_id'])) {
            abort_unless(WorkflowDefinition::whereKey($valid['workflow_definition_id'])->where('user_id', $definition->user_id)->where('project_id', $definition->project_id)->exists(), 422, 'Workflow source is outside the workflow project.');
        }
        if (isset($valid['repository_id'])) {
            abort_unless(Repository::whereKey($valid['repository_id'])->where('user_id', $definition->user_id)->where('project_id', $definition->project_id)->exists(), 422, 'GitHub source is outside the workflow project.');
        }
        if ($kind === 'workspace.file_changed') {
            abort_unless($definition->project_id && Device::where('user_id', $definition->user_id)->where('device_id', $valid['device_id'])->whereNull('revoked_at')->exists(), 422, 'File events require an owned project and device.');
            $valid['root_path'] = AutomationGrantService::canonicalRoot($valid['root_path']);
            $valid['excludes'] ??= ['.git/**', 'node_modules/**', 'vendor/**'];
            foreach (array_merge($valid['paths'], $valid['excludes']) as $path) {
                abort_unless($this->safeRelativePath($path, true), 422, 'Watch paths must be relative and cannot contain traversal.');
            }
            $valid['debounce_seconds'] ??= 2;
        }

        return $valid;
    }

    public function enqueueDue(int $limit = 250): int
    {
        $count = 0;
        foreach (WorkflowTrigger::where('enabled', true)->where('kind', 'schedule')->where('next_due_at', '<=', now())->orderBy('next_due_at')->limit($limit)->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$count) {
                $trigger = WorkflowTrigger::whereKey($id)->lockForUpdate()->firstOrFail();
                if (! $trigger->enabled || ! $trigger->next_due_at || $trigger->next_due_at->isFuture()) {
                    return;
                }
                $config = $trigger->config;
                $due = isset($config['cron']) ? Carbon::instance((new CronExpression($config['cron']))->getPreviousRunDate(now(), 0, true, $config['timezone']))->utc() : Carbon::parse($config['run_at'])->utc();
                // UTC delivery identity and a local wall-clock key prevent duplicate execution in the repeated DST hour.
                $wallKey = $due->copy()->setTimezone($config['timezone'])->format('Y-m-d H:i');
                if ($trigger->last_wall_key !== $wallKey) {
                    app(WorkflowEventService::class)->record((int) $trigger->user_id, $trigger->project_id, 'schedule', 'schedule:'.$trigger->id, $due->toISOString(), [
                        '_trigger_id' => $trigger->id, 'scheduled_at' => $due->toISOString(), 'timezone' => $config['timezone'], 'coalesced' => $trigger->next_due_at->lt($due),
                    ]);
                    $count++;
                }
                $trigger->update(['last_wall_key' => $wallKey, 'next_due_at' => isset($config['cron']) ? $this->nextDue($config, now()) : null]);
            });
        }

        return $count;
    }

    public function nextDue(array $config, Carbon $after): Carbon
    {
        if (isset($config['run_at'])) {
            return Carbon::parse($config['run_at'])->utc();
        }

        return Carbon::instance((new CronExpression($config['cron']))->getNextRunDate($after, 0, false, $config['timezone']))->utc();
    }

    public function safeRelativePath(string $path, bool $glob = false): bool
    {
        if ($path === '.' && ! $glob) {
            return true;
        }
        $path = str_replace('\\', '/', $path);

        return $path !== '' && ! str_starts_with($path, '/') && ! preg_match('/[\x00-\x1f:]/', $path)
            && ! in_array('..', explode('/', $path), true) && ! in_array('', explode('/', $path), true);
    }
}
