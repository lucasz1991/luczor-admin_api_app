<?php

$rawCatalog = trim((string) env('LUCZOR_LOCAL_MODEL_CATALOG_JSON', ''));
$catalogOverride = $rawCatalog === '' ? null : json_decode($rawCatalog, true);
$healthPolicy = ['cooldown_ms' => 300000, 'max_consecutive_failures' => 2];
$emptyCapacity = [
    'min_total_ram_bytes' => null,
    'min_available_ram_bytes' => null,
    'min_vram_bytes' => null,
    'min_storage_free_bytes' => null,
    'max_startup_seconds' => null,
    'benchmark_thresholds' => null,
];
$tierDefinitions = [
    ['alxis955-qwe2.5-coder-uncensored', 'Stufe 1 · Alxis955/qwe2.5-coder-Uncensored', 'fallback'],
    ['darkmaniac7-qwen3.5-4b-uncensored-mnn', 'Stufe 2 · darkmaniac7/Qwen3.5-4B-uncensored-MNN', 'fallback'],
    ['blossomsai-qwen2.5-coder-14b-instruct-uncensored', 'Stufe 3 · BlossomsAI/Qwen2.5-Coder-14B-Instruct-Uncensored', 'fallback'],
    ['orcarouter-qwen3.8-27b-uncensored-q4-k-m', 'Stufe 4 · OrcaRouter Qwen3.8-27B Uncensored Q4_K_M', 'fallback'],
    ['thebloke-wizardlm-uncensored-falcon-40b-gptq', 'Stufe 5 · TheBloke/WizardLM-Uncensored-Falcon-40B-GPTQ', 'preferred'],
];
$configuredSchemaVersion = is_array($catalogOverride)
    ? (int) ($catalogOverride['schema_version'] ?? (count($catalogOverride['models'] ?? []) === 2 ? 1 : 2))
    : 2;
$defaultModels = array_map(static function (array $definition) use ($healthPolicy, $emptyCapacity): array {
    [$id, $displayName, $routingRole] = $definition;

    return [
        'id' => $id,
        'display_name' => $displayName,
        'execution_target' => 'local_llama_cpp',
        'release_channel' => 'stable',
        'routing_role' => $routingRole,
        'promoted' => true,
        // New repos remain metadata-only until a GGUF artifact, compatible
        // runtime, hashes, template and benchmark evidence are verified.
        'enabled' => false,
        'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation'],
        'context_limit' => null,
        'artifact' => null,
        'runtime' => null,
        'capacity_policy' => $emptyCapacity,
        'health_policy' => $healthPolicy,
        'chat_template_hash' => null,
        'evaluation_report_hash' => null,
        'license' => null,
    ];
}, $tierDefinitions);

return [
    'asset_directory' => env('LUCZOR_LOCAL_MODEL_ASSET_DIRECTORY', storage_path('app/local-model-assets')),
    'schema_version' => $configuredSchemaVersion,
    'catalog_version' => (int) env('LUCZOR_LOCAL_MODEL_CATALOG_VERSION', 2026091201),
    'policy_version' => (int) env('LUCZOR_LOCAL_MODEL_POLICY_VERSION', 2026091201),
    'ttl_seconds' => max(300, (int) env('LUCZOR_LOCAL_MODEL_MANIFEST_TTL_SECONDS', 86400)),
    'signing' => [
        'auto_generate' => (bool) env('LUCZOR_LOCAL_MODEL_AUTO_GENERATE_KEY', true),
        'managed_directory' => env('LUCZOR_LOCAL_MODEL_KEY_DIRECTORY', dirname(base_path()).'/.luczor-secrets'),
        'key_id' => env('LUCZOR_LOCAL_MODEL_SIGNING_KEY_ID', 'local-model-catalog-2026-01'),
        'private_key' => '',
        'private_key_file' => env('LUCZOR_LOCAL_MODEL_SIGNING_PRIVATE_KEY_FILE', ''),
        'expected_public_key_sha256' => env('LUCZOR_LOCAL_MODEL_EXPECTED_PUBLIC_KEY_SHA256', ''),
    ],
    // Invalid JSON is retained as an explicit configuration error. Silently
    // falling back to defaults could unexpectedly re-enable an older policy.
    'catalog_override_valid' => $rawCatalog === '' || (
        is_array($catalogOverride)
        && is_array($catalogOverride['models'] ?? null)
        && is_array($catalogOverride['routing'] ?? null)
    ),
    'models' => is_array($catalogOverride['models'] ?? null) ? $catalogOverride['models'] : $defaultModels,
    'routing' => is_array($catalogOverride['routing'] ?? null) ? $catalogOverride['routing'] : [
        'strategy' => 'local_first',
        'local_first' => true,
        // Prefer the strongest tier, then fall back by descending capacity.
        'preferred_model_id' => 'thebloke-wizardlm-uncensored-falcon-40b-gptq',
        'default_model_id' => 'thebloke-wizardlm-uncensored-falcon-40b-gptq',
        'fallback_model_ids' => [
            'orcarouter-qwen3.8-27b-uncensored-q4-k-m',
            'blossomsai-qwen2.5-coder-14b-instruct-uncensored',
            'darkmaniac7-qwen3.5-4b-uncensored-mnn',
            'alxis955-qwe2.5-coder-uncensored',
        ],
        'experimental_model_ids' => [],
        'experimental_opt_in_required' => true,
        'external_execution_target' => 'laravel_proxy',
        'external_allowed' => true,
        'external_requires_explicit_approval' => true,
        'no_silent_external_fallback' => true,
        'required_local_state' => [
            'model_enabled',
            'artifact_verified',
            'runtime_verified',
            'capacity_qualified',
            'health_eligible',
        ],
        'decision_reasons' => [
            'local_preferred',
            'local_fallback_capacity',
            'local_fallback_health',
            'local_unavailable',
            'external_approval_required',
            'external_policy_rejected',
        ],
        'egress_policy_version' => '1',
    ],
];
