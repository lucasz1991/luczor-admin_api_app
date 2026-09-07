<?php

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\ModelUseCase;
use App\Models\NetworkPolicy;
use App\Models\ProviderPriceSnapshot;

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
        foreach (self::TASKS as $role => $taskType) {
            $case = ModelUseCase::query()->where('slug', 'agent-'.$role)->with('entries.modelProfile.credential')->first();
            $candidates = [];
            foreach ($case->entries ?? [] as $entry) {
                $model = $entry->modelProfile;
                if (! $entry->active || ! $model?->active || ! $model->credential?->active) {
                    continue;
                }
                $info = collect($catalog['models'])->firstWhere('id', $model->model_id) ?? [];
                $price = ProviderPriceSnapshot::current($model->provider, $model->model_id);
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
            $networkReady = $case && NetworkPolicy::query()->where('key', $case->network_policy_key)->where('status', 'active')->exists();
            $modelsByRole[$role] = ['task_type' => $taskType, 'candidates' => $candidates, 'ready' => $case?->active && $networkReady && $candidates !== []];
        }
        $specialists = [];
        foreach (self::TASKS as $role => $taskType) {
            $specialists[$role] = ['target' => 'external', 'task_type' => $taskType];
        }
        $free = $specialists;
        $free['planning']['target'] = 'local';

        return [
            'version' => 1,
            'enabled' => $profile?->status === 'active',
            'default_preset' => $default,
            'presets' => [
                ['id' => 'free', 'label' => 'Lokale Planung + Free-Spezialisten', 'description' => 'Luczor steuert lokal. Recherche, Codeentwurf und Prüfung bearbeiten getrennte kostenlose Modelle; Tools führt Luczor lokal aus.', 'max_parallel' => $parallel, 'roles' => $free],
                ['id' => 'budget', 'label' => 'Günstige externe Planung + Free-Spezialisten', 'description' => 'Optionales kostenpflichtiges Planungsmodell mit eigener Kostenobergrenze; Spezialisten bleiben im kostenlosen Pool.', 'max_parallel' => $parallel, 'roles' => $specialists],
            ],
            'models_by_role' => $modelsByRole,
            'evaluation' => ['minimum_samples' => 5, 'requires_quality_evidence' => true, 'model_review_is_estimate' => true],
            'researched_at' => $catalog['researched_at'],
        ];
    }
}
