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
    ['bartowski-qwen2.5-coder-3b-abliterated-gguf', 'Stufe 1 · bartowski/Qwen2.5-Coder-3B-Instruct-abliterated-GGUF', 'fallback'],
    ['mradermacher-whiterabbitneo-v3-7b-gguf', 'Stufe 2 · mradermacher/WhiteRabbitNeo-V3-7B-GGUF · Cybersecurity', 'fallback'],
    ['blossomsai-qwen2.5-coder-14b-instruct-uncensored-gguf', 'Stufe 3 · BlossomsAI/Qwen2.5-Coder-14B-Instruct-Uncensored-GGUF', 'fallback'],
    ['orcarouter-qwen3.8-27b-uncensored-q4-k-m', 'Stufe 4 · OrcaRouter Qwen3.8-27B Uncensored Q4_K_M', 'fallback'],
    ['tobiaslogic-qwen2.5-coder-32b-abliterated-gguf', 'Stufe 5 · TobiasLogic/Qwen2.5-Coder-32B-abliterated-GGUF', 'preferred'],
];
$candidateMetadata = [
    'bartowski-qwen2.5-coder-3b-abliterated-gguf' => [
        'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding'],
        'context_limit' => 32768,
        'artifact' => [
            'url' => 'https://huggingface.co/bartowski/Qwen2.5-Coder-3B-Instruct-abliterated-GGUF/resolve/d3030e6c3380c87316eeb6b35cdf5c94a68f1986/Qwen2.5-Coder-3B-Instruct-abliterated-Q4_K_M.gguf',
            'sha256' => 'd5c108dfbdac44c738e45a84d5624716cfb8522d1410f46a7108167ee4bd0cac',
            'size_bytes' => 1929903488,
            'format' => 'gguf',
            'quantization' => 'Q4_K_M',
            'storage_class' => 'fixed_storage',
        ],
        'license' => 'Qwen Research License (HF card: qwen-research)',
    ],
    'mradermacher-whiterabbitneo-v3-7b-gguf' => [
        'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'cybersecurity'],
        'context_limit' => 32768,
        'artifact' => [
            'url' => 'https://huggingface.co/mradermacher/WhiteRabbitNeo-V3-7B-GGUF/resolve/272df46bc0e282a378f67bac725ce8481cc48d2f/WhiteRabbitNeo-V3-7B.Q4_K_M.gguf',
            'sha256' => 'b19da8c6aacffdedc7bcd6b7f7d7d4db900f7d5c49f5473de18bb58111b432e5',
            'size_bytes' => 4683075232,
            'format' => 'gguf',
            'quantization' => 'Q4_K_M',
            'storage_class' => 'fixed_storage',
        ],
        'license' => 'Apache-2.0',
    ],
    'blossomsai-qwen2.5-coder-14b-instruct-uncensored-gguf' => [
        'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding'],
        'context_limit' => 32768,
        'artifact' => [
            'url' => 'https://huggingface.co/BlossomsAI/Qwen2.5-Coder-14B-Instruct-Uncensored-GGUF/resolve/b15f5f5bf2c2ccaa66f82b58a1a410a5b74715d1/q4_k_m.gguf',
            'sha256' => '75062a7ba3575573cc421a2cbafbf69fb48d0ecb28da2684b577eb609097fbe4',
            'size_bytes' => 8988110272,
            'format' => 'gguf',
            'quantization' => 'Q4_K_M',
            'storage_class' => 'fixed_storage',
        ],
        'license' => 'MIT',
    ],
    'orcarouter-qwen3.8-27b-uncensored-q4-k-m' => [
        'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding'],
        'context_limit' => 8192,
        'artifact' => [
            'url' => 'https://huggingface.co/bartowski/orcarouter_Qwen3.8-27B-Uncensored-GGUF/resolve/87d37daf5e5eb72a926d8b413e08809a57f1a120/orcarouter_Qwen3.8-27B-Uncensored-Q4_K_M.gguf',
            'sha256' => '6c8c7658fe13eef22666aa89862f7fb70aa72109838cf19989e59b15875e5e08',
            'size_bytes' => 17772538112,
            'format' => 'gguf',
            'quantization' => 'Q4_K_M',
            'storage_class' => 'fixed_storage',
        ],
        'license' => 'Apache-2.0',
    ],
    'tobiaslogic-qwen2.5-coder-32b-abliterated-gguf' => [
        'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding'],
        'context_limit' => 32768,
        'artifact' => [
            'url' => 'https://huggingface.co/TobiasLogic/Qwen2.5-Coder-32B-abliterated-GGUF/resolve/b58cb0c8c2f8e9903be8b3c68974df1d6e13374e/qwen2.5-coder-32b-abliterated-Q4_K_M.gguf',
            'sha256' => '593e9be6fae0c8c4008bb279f6380154afea89aeed90d9e3f2130d0becc84908',
            'size_bytes' => 19851336416,
            'format' => 'gguf',
            'quantization' => 'Q4_K_M',
            'storage_class' => 'fixed_storage',
        ],
        'license' => 'Apache-2.0',
    ],
];
$configuredSchemaVersion = is_array($catalogOverride)
    ? (int) ($catalogOverride['schema_version'] ?? (count($catalogOverride['models'] ?? []) === 2 ? 1 : 2))
    : 2;
$defaultModels = array_map(static function (array $definition) use ($healthPolicy, $emptyCapacity, $candidateMetadata): array {
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
        'capabilities' => $candidateMetadata[$id]['capabilities'] ?? ['chat', 'reasoning', 'planning', 'execution_preparation'],
        'context_limit' => $candidateMetadata[$id]['context_limit'] ?? null,
        'artifact' => $candidateMetadata[$id]['artifact'] ?? null,
        'runtime' => null,
        'capacity_policy' => $emptyCapacity,
        'health_policy' => $healthPolicy,
        'chat_template_hash' => null,
        'evaluation_report_hash' => null,
        'license' => $candidateMetadata[$id]['license'] ?? null,
    ];
}, $tierDefinitions);

return [
    'asset_directory' => env('LUCZOR_LOCAL_MODEL_ASSET_DIRECTORY', storage_path('app/local-model-assets')),
    'schema_version' => $configuredSchemaVersion,
    'catalog_version' => (int) env('LUCZOR_LOCAL_MODEL_CATALOG_VERSION', 2026091202),
    'policy_version' => (int) env('LUCZOR_LOCAL_MODEL_POLICY_VERSION', 2026091202),
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
        'preferred_model_id' => 'tobiaslogic-qwen2.5-coder-32b-abliterated-gguf',
        'default_model_id' => 'tobiaslogic-qwen2.5-coder-32b-abliterated-gguf',
        'fallback_model_ids' => [
            'orcarouter-qwen3.8-27b-uncensored-q4-k-m',
            'blossomsai-qwen2.5-coder-14b-instruct-uncensored-gguf',
            'mradermacher-whiterabbitneo-v3-7b-gguf',
            'bartowski-qwen2.5-coder-3b-abliterated-gguf',
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
