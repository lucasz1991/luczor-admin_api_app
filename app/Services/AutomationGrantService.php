<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Project;
use App\Models\WorkflowAutomationGrant;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\DB;

/** Standing consent is a bounded capability envelope, never permission supplied by event data. */
class AutomationGrantService
{
    public function current(WorkflowDefinition $definition): ?WorkflowAutomationGrant
    {
        return WorkflowAutomationGrant::where('workflow_definition_id', $definition->id)->latest('id')->first();
    }

    public function configure(WorkflowDefinition $definition, array $data, int $userId, string $deviceId): WorkflowAutomationGrant
    {
        abort_unless((int) $definition->user_id === $userId && hash_equals($deviceId, $data['device_id']), 403, 'Automation approval must originate on the selected owned device.');
        abort_unless(Device::where('user_id', $userId)->where('device_id', $deviceId)->whereNull('revoked_at')->exists(), 403, 'Automation device is unavailable.');
        abort_unless(($data['local_approved'] ?? false) === true, 422, 'Complete local approval is required.');
        $projectExternalId = $definition->project_id ? Project::find($definition->project_id)?->external_id : null;
        abort_unless(($data['project_external_id'] ?? null) === $projectExternalId, 422, 'Automation project does not match the workflow.');
        $config = array_intersect_key($data, array_flip([
            'device_id', 'project_external_id', 'root_path', 'allowed_tasks', 'allowed_input_sources',
            'allowed_output_keys', 'egress_hosts', 'max_steps', 'max_runs_per_hour', 'max_input_bytes',
            'max_output_bytes', 'script_hashes', 'export_results', 'approved_revision',
        ]));
        $config += ['max_steps' => 100, 'max_runs_per_hour' => 20, 'max_input_bytes' => 65536, 'max_output_bytes' => 65536, 'script_hashes' => [], 'export_results' => false];
        foreach (['allowed_tasks', 'allowed_input_sources', 'allowed_output_keys', 'egress_hosts'] as $field) {
            $config[$field] = array_values(array_unique($config[$field] ?? []));
            sort($config[$field]);
        }
        $config['root_path'] = self::canonicalRoot($config['root_path']);
        $scopeHash = hash('sha256', self::canonicalJson($config));

        return DB::transaction(function () use ($definition, $data, $userId, $deviceId, $config, $scopeHash) {
            WorkflowDefinition::whereKey($definition->id)->lockForUpdate()->firstOrFail();
            if ($existing = WorkflowAutomationGrant::where('user_id', $userId)->where('operation_id', $data['operation_id'])->first()) {
                abort_unless((int) $existing->workflow_definition_id === (int) $definition->id && $existing->scope_hash === $scopeHash && $existing->status === $data['status'], 409, 'Automation operation ID was already used for different data.');

                return $existing;
            }

            return WorkflowAutomationGrant::create([
                'user_id' => $userId, 'workflow_definition_id' => $definition->id, 'project_id' => $definition->project_id,
                'device_id' => $deviceId, 'approved_revision' => $data['approved_revision'], 'status' => $data['status'],
                'config' => $config, 'scope_hash' => $scopeHash, 'operation_id' => $data['operation_id'],
            ]);
        });
    }

    public function authorizeRun(WorkflowDefinition $definition, array $graph, array $executionContext): array
    {
        $steps = $this->collectSteps($graph);
        $deviceSteps = array_filter($steps, fn (array $step) => WorkflowTaskCatalog::isClientTask((string) ($step['type'] ?? '')) || in_array($step['type'] ?? '', ['device_job', 'llm.local'], true));
        if ($deviceSteps === []) {
            return [];
        }
        $grant = $this->current($definition);
        abort_unless($grant && $grant->status === 'active', 409, 'needs_automation_approval');
        $config = $grant->config;
        abort_unless(($executionContext['device_id'] ?? null) === $grant->device_id, 409, 'Automation device exceeds the standing approval.');
        abort_unless((int) $grant->project_id === (int) $definition->project_id, 409, 'Automation project exceeds the standing approval.');
        abort_unless(count($steps) <= $config['max_steps'], 409, 'Automation step budget requires new approval.');
        abort_unless(! isset($executionContext['root_path']) || self::canonicalRoot((string) $executionContext['root_path']) === $config['root_path'], 409, 'Automation root exceeds the standing approval.');
        foreach ($steps as $step) {
            $this->assertTask($grant, (string) ($step['type'] ?? ''), $step['payload'] ?? [], (string) ($step['key'] ?? ''), false);
        }
        $activeHour = WorkflowRun::where('workflow_definition_id', $definition->id)->where('created_at', '>=', now()->subHour())->count();
        abort_unless($activeHour < $config['max_runs_per_hour'], 409, 'automation_hourly_budget');
        abort_unless(strlen(self::canonicalJson($executionContext['event'] ?? [])) <= $config['max_input_bytes'], 409, 'Automation event exceeds the approved input budget.');

        return $grant->toArray();
    }

    public function authorizeTask(WorkflowRun $run, string $taskType, array $resolvedPayload): array
    {
        $execution = $run->context['_execution'] ?? [];
        if (empty($execution['automatic'])) {
            return [];
        }
        $grantData = $execution['grant'] ?? [];
        $definition = WorkflowDefinition::findOrFail($grantData['workflow_definition_id'] ?? $run->workflow_definition_id);
        $grant = $this->current($definition);
        abort_unless($grant && $grant->status === 'active' && (int) $grant->id === (int) ($grantData['id'] ?? 0), 409, 'Automation approval was revoked or replaced.');
        abort_unless(Device::where('user_id', $run->user_id)->where('device_id', $grant->device_id)->whereNull('revoked_at')->exists(), 409, 'Automation device was revoked.');
        $this->assertTask($grant, $taskType, $resolvedPayload, (string) ($resolvedPayload['_step_key'] ?? ''), true);

        return $grant->toArray();
    }

    private function assertTask(WorkflowAutomationGrant $grant, string $type, array $payload, string $stepKey, bool $resolved): void
    {
        $config = $grant->config;
        abort_unless(in_array($type, $config['allowed_tasks'], true), 409, "Automation action {$type} requires new approval.");
        abort_unless(strlen(self::canonicalJson($payload)) <= $config['max_input_bytes'], 409, 'Automation input budget exceeded.');
        foreach ($payload['input_bindings'] ?? [] as $reference) {
            $source = explode('.', (string) $reference)[0];
            abort_unless(in_array($source, $config['allowed_input_sources'], true), 409, 'Automation input source requires new approval.');
        }
        $walk = function (mixed $value) use (&$walk, $config): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['source']) || isset($value['$ref'])) {
                $source = explode('.', (string) ($value['source'] ?? $value['$ref']))[0];
                if (in_array($source, ['input', 'event', 'steps'], true)) {
                    abort_unless(in_array($source, $config['allowed_input_sources'], true), 409, 'Automation input source requires new approval.');
                }
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($payload);
        foreach (['root_path', 'project_dir', 'workspace_root_id', 'workspace_root_path'] as $rootField) {
            if (isset($payload[$rootField]) && is_string($payload[$rootField])) {
                abort_unless(self::canonicalRoot($payload[$rootField]) === $config['root_path'], 409, 'Automation root requires new approval.');
            }
        }
        if (isset($payload['device_id'])) {
            abort_unless($payload['device_id'] === $grant->device_id, 409, 'Automation device requires new approval.');
        }
        if (isset($payload['url']) && is_string($payload['url'])) {
            $host = strtolower((string) parse_url($payload['url'], PHP_URL_HOST));
            abort_unless($host !== '' && in_array($host, $config['egress_hosts'], true), 409, 'Automation network target requires new approval.');
        }
        if (in_array($type, ['python.run', 'node.run', 'agent.dispatch'], true)) {
            $text = $payload[$type === 'agent.dispatch' ? 'prompt' : 'code'] ?? null;
            // Dynamic code/prompt bindings must be approved for their actual bytes at dispatch.
            if ($resolved || is_string($text)) {
                $hash = is_string($text) ? hash('sha256', $text) : '';
                abort_unless($hash !== '' && in_array($hash, array_values($config['script_hashes'] ?? []), true), 409, 'Changed script or agent prompt requires new approval.');
                if ($stepKey !== '' && isset($config['script_hashes'][$stepKey])) {
                    abort_unless(hash_equals($config['script_hashes'][$stepKey], $hash), 409, 'Changed script or agent prompt requires new approval.');
                }
            }
        }
    }

    private function collectSteps(array $graph): array
    {
        $steps = $graph['definition']['steps'] ?? $graph['steps'] ?? [];
        foreach ($graph['children'] ?? [] as $child) {
            $steps = array_merge($steps, $this->collectSteps($child));
        }

        return $steps;
    }

    public static function canonicalRoot(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');

        return preg_match('/^[A-Za-z]:\//', $path) || str_starts_with($path, '//') ? strtolower($path) : $path;
    }

    public static function canonicalJson(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
