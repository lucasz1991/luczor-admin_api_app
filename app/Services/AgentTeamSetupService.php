<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Support\Carbon;

/** Admin-only configuration inspection; never creates routes or calls a provider. */
final class AgentTeamSetupService
{
    private const LABELS = ['planning' => 'Planung', 'research' => 'Recherche', 'coding' => 'Codeentwurf', 'review' => 'Prüfung'];

    public function __construct(private AgentTeamPolicyService $policy) {}

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $policy = $this->policy->payload();
        $catalog = $this->policy->catalog();
        $catalogCurrent = false;
        $savedDate = $catalog['researched_at'] ?? null;
        if (is_string($savedDate) && trim($savedDate) !== '') {
            try {
                $researchedAt = Carbon::parse($savedDate);
                $catalogCurrent = $researchedAt->gte(now()->subDays(14)) && $researchedAt->lte(now());
            } catch (\Throwable) {
                // An invalid date is not evidence of a current catalog.
            }
        }
        $roles = [];
        foreach (self::LABELS as $role => $label) {
            $entry = $policy['models_by_role'][$role];
            $roles[$role] = [
                'label' => $label,
                'catalog_candidates' => collect($catalog['models'])->filter(fn (array $model): bool => in_array($role, $model['roles'], true))->count(),
                'ready' => $entry['ready'],
                'reason' => $entry['reason'],
                'reason_code' => $entry['reason_code'],
            ];
        }
        $selected = collect($policy['presets'])->firstWhere('id', $policy['default_preset']);
        $requested = array_keys(array_filter($selected['roles'], fn (array $role): bool => $role['target'] === 'external'));
        $unavailable = array_values(array_filter($requested, fn (string $role): bool => ! $roles[$role]['ready']));

        return [
            'policy' => $policy,
            'catalog' => $catalog,
            'roles' => $roles,
            'catalog_current' => $catalogCurrent,
            'credential_count' => ProviderCredential::query()->where('provider', 'openrouter')->where('active', true)->where('request_format', 'chat_completions')->count(),
            'selected_label' => $selected['label'],
            'requested_count' => count($requested),
            'configured_count' => count($requested) - count($unavailable),
            'unavailable_labels' => array_map(fn (string $role): string => self::LABELS[$role], $unavailable),
        ];
    }

    /** A saved toggle or created row is not a complete team. @return array{tone:string,message:string} */
    public function result(string $savedMessage): array
    {
        $setup = $this->inspect();
        if (! $setup['policy']['enabled']) {
            return ['tone' => 'info', 'message' => $savedMessage.' Externe Teams bleiben deaktiviert.'];
        }
        if ($setup['unavailable_labels'] !== []) {
            return [
                'tone' => 'warning',
                'message' => $savedMessage.' Einrichtung noch offen für: '.implode(', ', $setup['unavailable_labels']).'. Die konkreten Voraussetzungen stehen unten; diese Rollen sind noch nicht ausführbar.',
            ];
        }

        return ['tone' => 'success', 'message' => $savedMessage.' Das gewählte Team ist vollständig konfiguriert. Eine echte Provideranfrage wurde dabei nicht ausgeführt.'];
    }
}
