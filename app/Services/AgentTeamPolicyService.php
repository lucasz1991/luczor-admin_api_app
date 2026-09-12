<?php

namespace App\Services;

use App\Exceptions\RoutingPolicyException;
use App\Models\AgentProfile;
use App\Models\ModelUseCase;
use App\Models\NetworkPolicy;
use App\Models\ProviderPriceSnapshot;
use App\Services\Llm\ProviderWireFormat;

final class AgentTeamPolicyService
{
    public const POLICY_KEY = 'luczor.agent-team-policy';

    public const CATALOG_KEY = 'luczor.agent-model-catalog';

    public const TASKS = ['planning' => 'agent.planning', 'research' => 'agent.research', 'coding' => 'agent.coding', 'review' => 'agent.review'];

    /** @return array<string,mixed> */
    public function catalog(): array
    {
        $saved = AgentProfile::query()->where('key', self::CATALOG_KEY)->first()?->config;

        return is_array($saved) && is_array($saved['models'] ?? null) ? $saved : config('agent_teams');
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        $profile = AgentProfile::query()->where('key', self::POLICY_KEY)->first();
        $config = is_array($profile?->config) ? $profile->config : [];
        $default = in_array($config['default_preset'] ?? '', ['free', 'budget'], true) ? $config['default_preset'] : 'free';
        $parallel = max(1, min(3, (int) ($config['max_parallel'] ?? 2)));
        $catalog = $this->catalog();
        $modelsByRole = [];
        $bindings = [];
        foreach (self::TASKS as $role => $taskType) {
            $case = ModelUseCase::query()->where('slug', 'agent-'.$role)->with('entries.modelProfile.credential')->first();
            $network = $case ? NetworkPolicy::query()->where('key', $case->network_policy_key)->first() : null;
            $bindings[$role] = ['case' => $case?->only(['active', 'policy_version', 'routing_strategy', 'max_attempts', 'max_input_tokens', 'max_cost_usd', 'prompt_template_key', 'network_policy_key']),
                'network' => $network?->only(['status', 'max_attempts', 'max_cost_usd', 'max_input_tokens', 'max_output_tokens', 'request_timeout_ms', 'connect_timeout_ms', 'backoff_ms', 'config']), 'entries' => []];
            $candidates = [];
            foreach ($case->entries ?? [] as $entry) {
                $model = $entry->modelProfile;
                $price = $model ? ProviderPriceSnapshot::current($model->provider, $model->model_id) : null;
                $bindings[$role]['entries'][] = ['entry' => $entry->only(['active', 'sort_order']),
                    'model' => $model?->only(['id', 'model_id', 'provider', 'active', 'temperature', 'max_tokens', 'context_window', 'capabilities']),
                    'credential' => $model?->credential?->only(['id', 'provider', 'active', 'base_url', 'request_format']),
                    'price' => $price?->only(['input_per_million', 'output_per_million', 'cache_read_per_million', 'cache_write_per_million', 'valid_from', 'valid_until'])];
                if (! $entry->active || ! $model?->active || ! $model->credential
                    || ! ProviderWireFormat::isCompatible($model, $model->credential)) {
                    continue;
                }
                $info = collect($catalog['models'])->firstWhere('id', $model->model_id) ?? [];
                if ($price === null) {
                    continue;
                }
                $candidates[] = [
                    'id' => $model->model_id, 'name' => $model->name, 'provider' => $model->provider,
                    'input_per_million' => $price->input_per_million,
                    'output_per_million' => $price->output_per_million,
                    'data_policy' => $info['data_policy'] ?? 'Provider-Datennutzung vor Freigabe prüfen.',
                ];
            }
            $reasonCode = $this->readinessReason($profile, $case, $taskType);
            $toolsReasonCode = $reasonCode ?? $this->readinessReason($profile, $case, $taskType, ['tools']);
            $modelsByRole[$role] = ['task_type' => $taskType, 'candidates' => $candidates, 'ready' => $reasonCode === null,
                'tools_ready' => $toolsReasonCode === null,
                'tools_reason_code' => $toolsReasonCode,
                'reason_code' => $reasonCode, 'reason' => $this->readinessMessage($reasonCode),
                'max_cost_usd' => $this->tightest($case?->max_cost_usd, $network?->max_cost_usd),
                'max_output_tokens' => $network?->max_output_tokens === null ? null : (int) $network->max_output_tokens,
                'max_attempts' => $this->tightest($case?->max_attempts, $network?->max_attempts)];
        }
        $specialists = [];
        foreach (self::TASKS as $role => $taskType) {
            $specialists[$role] = ['target' => 'external', 'task_type' => $taskType];
        }
        $free = $specialists;
        $free['planning']['target'] = 'local';
        $freeUnavailable = array_values(array_filter(['research', 'coding', 'review'], fn (string $role): bool => ! $modelsByRole[$role]['ready']));
        $budgetUnavailable = array_values(array_filter(array_keys(self::TASKS), fn (string $role): bool => ! $modelsByRole[$role]['ready']));

        $payload = [
            'version' => 1,
            'enabled' => $profile?->type === 'team_policy' && $profile->status === 'active',
            'default_preset' => $default,
            'presets' => [
                ['id' => 'free', 'label' => 'Lokale Planung + Free-Spezialisten', 'description' => 'Luczor steuert lokal. Recherche, Codeentwurf und Prüfung bearbeiten getrennte kostenlose Modelle; Tools führt Luczor lokal aus.', 'max_parallel' => $parallel, 'roles' => $free, 'ready' => $freeUnavailable === [], 'unavailable_roles' => $freeUnavailable],
                ['id' => 'budget', 'label' => 'Günstige externe Planung + Free-Spezialisten', 'description' => 'Optionales kostenpflichtiges Planungsmodell mit eigener Kostenobergrenze; Spezialisten bleiben im kostenlosen Pool.', 'max_parallel' => $parallel, 'roles' => $specialists, 'ready' => $budgetUnavailable === [], 'unavailable_roles' => $budgetUnavailable],
            ],
            'models_by_role' => $modelsByRole,
            'evaluation' => ['minimum_samples' => 5, 'requires_quality_evidence' => true, 'model_review_is_estimate' => true],
            'researched_at' => $catalog['researched_at'],
        ];
        $payload['revision'] = hash('sha256', json_encode([$payload, $bindings], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return $payload;
    }

    /** Configuration preflight only; no network request and no inference or availability claim. */
    private function readinessReason(?AgentProfile $profile, ?ModelUseCase $case, string $taskType, array $requiredCapabilities = []): ?string
    {
        if (! $profile || $profile->type !== 'team_policy') {
            return 'agent_team_not_configured';
        }
        if ($profile->status !== 'active') {
            return 'agent_team_disabled';
        }
        if (! $case) {
            return 'routing_use_case_unavailable';
        }
        if (! $case->active) {
            return 'agent_role_disabled';
        }
        try {
            app(ProviderPolicyService::class)->resolve($taskType, $requiredCapabilities, [
                'messages' => [['role' => 'user', 'content' => 'Konfigurationsprüfung']],
            ]);
        } catch (RoutingPolicyException $exception) {
            return $exception->reasonCode;
        }

        return null;
    }

    private function readinessMessage(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'agent_team_not_configured' => 'Die Agententeam-Konfiguration fehlt. Im Admin einen OpenRouter-Zugang wählen und Teams ergänzen.',
            'agent_team_disabled' => 'Externe Agententeams sind im Admin deaktiviert.',
            'agent_role_disabled' => 'Die Rollenroute ist im Admin deaktiviert.',
            'routing_use_case_unavailable' => 'Für diese Rolle fehlt eine Modellroute. Katalog prüfen und Teams ergänzen.',
            'routing_no_candidates' => 'Die Rollenroute hat keine aktiven Modelle. Rollenketten prüfen oder leere aktive Ketten ergänzen.',
            'routing_credential_incompatible' => 'Kein kompatibler aktiver Provider-Zugang ist für diese Rolle hinterlegt.',
            'routing_price_unavailable' => 'Für die Rollenmodelle fehlen gültige Preise. Katalog neu prüfen und Teams ergänzen.',
            'routing_network_policy_unavailable', 'routing_network_policy_retry_statuses_invalid' => 'Die Netzwerkrichtlinie dieser Rolle fehlt, ist deaktiviert oder ungültig.',
            'routing_budget_exceeded', 'routing_budget_policy_invalid' => 'Die Kosten- oder Tokenlimits dieser Rolle sind ungültig oder reichen nicht für die konfigurierten Modelle.',
            'routing_capability_unavailable' => 'Die Rollenmodelle unterstützen den benötigten Chat-Vertrag nicht.',
            'routing_context_window_exceeded' => 'Das Ausgabelimit passt nicht in das Kontextfenster der Rollenmodelle.',
            default => 'Die Rollenroute ist noch nicht ausführbar. Modelle und Routingregeln im Admin prüfen.',
        };
    }

    private function tightest(mixed $first, mixed $second): ?float
    {
        $limits = array_filter([$first, $second], fn (mixed $value): bool => is_numeric($value) && (float) $value >= 0);

        return $limits === [] ? null : (float) min($limits);
    }
}
