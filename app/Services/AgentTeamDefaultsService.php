<?php

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\NetworkPolicy;
use App\Models\ProviderCredential;
use App\Models\ProviderPriceSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AgentTeamDefaultsService
{
    public function __construct(private AgentTeamPolicyService $policy) {}

    /** Public metadata only: never attach a stored provider credential to this GET. @return array<string,mixed> */
    public function refreshResearch(): array
    {
        try {
            $response = Http::connectTimeout(10)->timeout(30)->acceptJson()->get('https://openrouter.ai/api/v1/models')->throw();
        } catch (ConnectionException|RequestException) {
            throw ValidationException::withMessages(['research' => 'Der öffentliche OpenRouter-Katalog ist derzeit nicht erreichbar. Die gespeicherte Recherche bleibt erhalten.']);
        }
        $rows = $response->json('data');
        if (! is_array($rows)) {
            throw ValidationException::withMessages(['research' => 'OpenRouter hat keinen gültigen Modellkatalog geliefert.']);
        }
        $models = [];
        foreach (config('agent_teams.models') as $baseline) {
            $live = collect($rows)->firstWhere('id', $baseline['id']);
            $pricing = $live['pricing'] ?? [];
            if (! is_array($live) || ! is_numeric($pricing['prompt'] ?? null) || ! is_numeric($pricing['completion'] ?? null)
                || ! is_numeric($live['context_length'] ?? null) || (int) $live['context_length'] < 1) {
                continue;
            }
            $input = (float) $pricing['prompt'] * 1000000;
            $output = (float) $pricing['completion'] * 1000000;
            if ($input < 0 || $output < 0 || (str_ends_with($baseline['id'], ':free') && ($input !== 0.0 || $output !== 0.0))) {
                continue;
            }
            $models[] = array_merge($baseline, ['input_per_million' => $input, 'output_per_million' => $output, 'context_window' => min(2000000, (int) $baseline['context_window'], (int) $live['context_length'])]);
        }
        if ($models === []) {
            throw ValidationException::withMessages(['research' => 'Keiner der geprüften Kandidaten ist aktuell mit gültigen Preisen verfügbar.']);
        }
        $catalog = ['researched_at' => now()->toIso8601String(), 'source' => 'https://openrouter.ai/api/v1/models', 'models' => $models];
        AgentProfile::updateOrCreate(['key' => AgentTeamPolicyService::CATALOG_KEY], ['name' => 'Agenten-Modellrecherche', 'type' => 'model_catalog', 'status' => 'draft', 'config' => $catalog]);

        return $catalog;
    }

    /** Adds missing entries; edited or disabled profiles, chains and policies remain authoritative. @return array<string,int> */
    public function prepare(int $credentialId, bool $fillEmptyRoutes = false): array
    {
        $credential = ProviderCredential::query()->whereKey($credentialId)->where('provider', 'openrouter')->where('active', true)->where('request_format', 'chat_completions')->first();
        if (! $credential) {
            throw ValidationException::withMessages(['provider_credential_id' => 'Bitte einen aktiven OpenRouter-Zugang mit Chat Completions wählen.']);
        }
        $catalog = $this->policy->catalog();
        $researchedAt = Carbon::parse($catalog['researched_at']);
        if ($researchedAt->lt(now()->subDays(14))) {
            throw ValidationException::withMessages(['research' => 'Bitte zuerst den OpenRouter-Katalog neu prüfen; der Preisstand ist älter als 14 Tage.']);
        }

        return DB::transaction(function () use ($credential, $catalog, $researchedAt, $fillEmptyRoutes): array {
            $counts = ['models_created' => 0, 'roles_created' => 0, 'entries_created' => 0];
            foreach (['free' => 0.0, 'planning' => 0.05] as $kind => $limit) {
                NetworkPolicy::firstOrCreate(['key' => 'agent.'.$kind], [
                    'name' => $kind === 'free' ? 'Agenten: ausschließlich kostenlos' : 'Agenten: günstige Planung',
                    'status' => 'active', 'connect_timeout_ms' => 10000, 'request_timeout_ms' => 90000,
                    'max_attempts' => $kind === 'free' ? 2 : 1, 'backoff_ms' => 500,
                    'max_cost_usd' => $limit, 'max_input_tokens' => 32000, 'max_output_tokens' => 4096,
                    'config' => ['retry_statuses' => [0, 408, 429, 500, 502, 503, 504]],
                ]);
            }
            $profiles = [];
            foreach ($catalog['models'] as $model) {
                $profile = ModelProfile::firstOrCreate(['slug' => 'agent-'.Str::slug($model['id'])], [
                    'name' => $model['name'], 'provider' => 'openrouter', 'provider_credential_id' => $credential->id,
                    'model_id' => $model['id'], 'temperature' => 0.2, 'max_tokens' => 4096,
                    'purpose' => 'agent', 'active' => true, 'capabilities' => ['chat'], 'context_window' => $model['context_window'],
                    'meta' => ['source' => 'agent-research-v1', 'researched_at' => $catalog['researched_at'], 'data_policy' => $model['data_policy']],
                ]);
                $counts['models_created'] += (int) $profile->wasRecentlyCreated;
                $profiles[$model['id']] = $profile;
                $price = ProviderPriceSnapshot::current('openrouter', $model['id']);
                $refreshManagedPrice = $price?->source === 'agent-research-v1'
                    && ($price->meta['researched_at'] ?? null) !== $catalog['researched_at'];
                if ($price === null || $refreshManagedPrice) {
                    if ($refreshManagedPrice) {
                        $price->update(['valid_until' => now()]);
                    }
                    ProviderPriceSnapshot::create([
                        'provider_id' => 'openrouter', 'model_id' => $model['id'], 'currency' => 'USD',
                        'input_per_million' => $model['input_per_million'], 'output_per_million' => $model['output_per_million'],
                        'cache_read_per_million' => 0, 'cache_write_per_million' => 0,
                        'source' => 'agent-research-v1', 'valid_from' => now(), 'valid_until' => $researchedAt->copy()->addDays(14),
                        'meta' => ['researched_at' => $catalog['researched_at'], 'source' => $catalog['source']],
                    ]);
                }
            }
            foreach (AgentTeamPolicyService::TASKS as $role => $taskType) {
                $candidates = collect($catalog['models'])->filter(fn (array $model): bool => in_array($role, $model['roles'], true))
                    ->sortBy(fn (array $model): int => array_search($role, $model['roles'], true));
                // A partial public catalog must not create empty chains that later look configured.
                if ($candidates->isEmpty()) {
                    continue;
                }
                $case = ModelUseCase::firstOrCreate(['slug' => 'agent-'.$role], [
                    'name' => 'Agent: '.$role, 'active' => true, 'description' => 'Text-Spezialist; lokale Tools verbleiben unter Luczor-Kontrolle.',
                    'policy_version' => 1, 'routing_strategy' => 'ranked', 'max_attempts' => $role === 'planning' ? 1 : 2,
                    'max_input_tokens' => 32000, 'max_cost_usd' => $role === 'planning' ? 0.05 : 0,
                    'network_policy_key' => $role === 'planning' ? 'agent.planning' : 'agent.free',
                ]);
                $newCase = $case->wasRecentlyCreated;
                $counts['roles_created'] += (int) $newCase;
                // Refill only when explicitly requested, and never alter disabled or populated chains.
                if (! $newCase && (! $fillEmptyRoutes || ! $case->active || $case->entries()->exists())) {
                    continue;
                }
                foreach ($candidates->values() as $order => $model) {
                    ModelUseCaseEntry::create(['model_use_case_id' => $case->id, 'model_profile_id' => $profiles[$model['id']]->id, 'sort_order' => $order + 1, 'active' => true, 'notes' => 'Recherchierter Startkandidat; Qualität muss in Luczor gemessen werden.']);
                    $counts['entries_created']++;
                }
            }
            AgentProfile::firstOrCreate(['key' => AgentTeamPolicyService::POLICY_KEY], [
                'name' => 'Luczor: lokale Steuerung und externe Spezialisten', 'type' => 'team_policy', 'status' => 'active',
                'config' => ['default_preset' => 'free', 'max_parallel' => 2],
            ]);

            return $counts;
        });
    }
}
