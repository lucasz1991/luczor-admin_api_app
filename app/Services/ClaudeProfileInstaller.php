<?php

namespace App\Services;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\NetworkPolicy;
use App\Models\ProviderPriceSnapshot;

/**
 * Idempotent catalog for "Claude as main model": model profiles, the
 * main-claude use case, its network policy and current list prices.
 * Existing rows are never overwritten, so admin edits survive re-runs.
 *
 * Profiles carry no provider_credential_id: chat.main always runs on the
 * credential resolved per request (personal key or organization access).
 */
class ClaudeProfileInstaller
{
    public const NETWORK_POLICY_KEY = 'proxy.anthropic.main';

    /**
     * Prices in USD per million tokens. Cache prices default conservatively
     * to the input price (read) and 1.25x input (write) where no list price
     * is recorded; adjust the snapshot in the admin when needed.
     *
     * @var array<int,array{slug: string, label: string, description: string, visible: bool, active: bool, input: float, output: float, cache_read: float|null}>
     */
    public const MODELS = [
        ['slug' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5', 'description' => 'Leistungsstark für anspruchsvolle Aufgaben', 'visible' => true, 'active' => true, 'input' => 4.0, 'output' => 20.0, 'cache_read' => 0.20],
        ['slug' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5', 'description' => 'Schnell und vielseitig für den Alltag', 'visible' => true, 'active' => true, 'input' => 2.0, 'output' => 10.0, 'cache_read' => 0.10],
        ['slug' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5', 'description' => 'Am schnellsten, für einfache Aufgaben', 'visible' => true, 'active' => true, 'input' => 0.10, 'output' => 0.50, 'cache_read' => null],
        ['slug' => 'claude-fable-5-2', 'label' => 'Claude Fable 5.2', 'description' => 'Höchste Leistungsstufe', 'visible' => false, 'active' => false, 'input' => 8.0, 'output' => 40.0, 'cache_read' => 0.20],
    ];

    /** @return array{profiles: int, entries: int, prices: int} number of rows created */
    public function install(): array
    {
        $created = ['profiles' => 0, 'entries' => 0, 'prices' => 0];

        NetworkPolicy::query()->firstOrCreate(['key' => self::NETWORK_POLICY_KEY], [
            'name' => 'Anthropic Claude (Hauptmodell)',
            'status' => 'active',
            'connect_timeout_ms' => 10000,
            // Long thinking turns stream for minutes.
            'request_timeout_ms' => 600000,
            'max_attempts' => 1,
            'backoff_ms' => 250,
            'max_input_tokens' => 900000,
            'max_output_tokens' => 64000,
            'config' => ['retry_statuses' => [0, 408, 409, 425, 429, 500, 502, 503, 504, 529]],
        ]);

        $useCase = ModelUseCase::query()->firstOrCreate(['slug' => ProviderPolicyService::MAIN_USE_CASE], [
            'name' => 'Claude Hauptmodell',
            'description' => 'Claude als vom Benutzer gewähltes Hauptmodell (chat.main).',
            'active' => true,
            'policy_version' => 1,
            'routing_strategy' => 'manual',
            // No silent model switch: one attempt on the selected model.
            'max_attempts' => 1,
            'network_policy_key' => self::NETWORK_POLICY_KEY,
        ]);

        foreach (self::MODELS as $index => $model) {
            $profile = ModelProfile::query()->firstOrCreate(['slug' => $model['slug']], [
                'name' => $model['label'],
                'provider' => ClaudeAccessService::PROVIDER,
                'provider_credential_id' => null,
                'model_id' => $model['slug'],
                // Stored for schema compatibility only; never sent to Anthropic.
                'temperature' => 1.0,
                'max_tokens' => 64000,
                'purpose' => 'chat',
                'active' => true,
                'capabilities' => ['chat', 'tools'],
                'context_window' => 1000000,
                'meta' => [
                    'composer_label' => $model['label'],
                    'composer_description' => $model['description'],
                    'composer_visible' => $model['visible'],
                    'efforts' => ClaudeAccessService::DEFAULT_EFFORTS,
                ],
            ]);
            $created['profiles'] += $profile->wasRecentlyCreated ? 1 : 0;

            $entry = ModelUseCaseEntry::query()->firstOrCreate(
                ['model_use_case_id' => $useCase->id, 'model_profile_id' => $profile->id],
                ['sort_order' => $index + 1, 'active' => $model['active']],
            );
            $created['entries'] += $entry->wasRecentlyCreated ? 1 : 0;

            if (ProviderPriceSnapshot::current(ClaudeAccessService::PROVIDER, $model['slug']) === null) {
                ProviderPriceSnapshot::query()->create([
                    'provider_id' => ClaudeAccessService::PROVIDER,
                    'model_id' => $model['slug'],
                    'currency' => 'USD',
                    'input_per_million' => $model['input'],
                    'output_per_million' => $model['output'],
                    'cache_read_per_million' => $model['cache_read'] ?? $model['input'],
                    'cache_write_per_million' => round($model['input'] * 1.25, 8),
                    'source' => 'claude-installer',
                    'valid_from' => now(),
                    'meta' => ['list_price_as_of' => '2026-11'],
                ]);
                $created['prices']++;
            }
        }

        return $created;
    }
}
