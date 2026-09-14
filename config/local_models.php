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
$unsupportedMediaFeatures = [
    'text_generation' => 'candidate',
    'text_edit' => 'candidate',
    'tool_calling' => 'candidate',
    'coding' => 'candidate',
    'cybersecurity' => 'unknown',
    'image_to_text' => 'unsupported',
    'audio_input' => 'unsupported',
    'audio_output' => 'unsupported',
    'uncensored' => 'candidate',
];
$tierDefinitions = [
    ['dolphin3-qwen2.5-3b-gguf', 'Stufe 1 · Dolphin3.0 Qwen2.5 3B GGUF · uncensored/tool data', 'fallback'],
    ['dolphin3-llama3.1-8b-gguf', 'Stufe 2 · Dolphin3.0 Llama3.1 8B GGUF · uncensored/tool data', 'fallback'],
    ['rootmonster-qwen3-14b-abliterated-gguf', 'Stufe 3 · RootMonsteR Qwen3 14B Abliterated GGUF', 'fallback'],
    ['orcarouter-qwen3.8-27b-uncensored-q4-k-m', 'Stufe 4 · OrcaRouter Qwen3.8-27B Uncensored Q4_K_M', 'fallback'],
    ['huihui-qwen3-30b-a3b-instruct-abliterated-gguf', 'Stufe 5 · Huihui Qwen3 30B A3B Instruct Abliterated GGUF', 'preferred'],
];
$candidateMetadata = [
    'dolphin3-qwen2.5-3b-gguf' => [
        'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'tools', 'uncensored'],
        'features' => $unsupportedMediaFeatures,
        'context_limit' => 32768,
        'artifact' => [
            'url' => 'https://huggingface.co/bartowski/Dolphin3.0-Qwen2.5-3b-GGUF/resolve/4f84a9b5b7ecc4c49367838cf226677008f37c1e/Dolphin3.0-Qwen2.5-3b-Q4_K_M.gguf',
            'sha256' => '0cb1908c5f444e1dc2c5b5619d62ac4957a22ad39cd42f2d0b48e2d8b1c358ab',
            'size_bytes' => 1929906144,
            'format' => 'gguf',
            'quantization' => 'Q4_K_M',
            'storage_class' => 'fixed_storage',
        ],
        'license' => 'Other (Dolphin/Qwen terms; review before commercial redistribution)',
    ],
    'dolphin3-llama3.1-8b-gguf' => [
        'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'tools', 'uncensored'],
        'features' => $unsupportedMediaFeatures,
        'context_limit' => 32768,
        'artifact' => [
            'url' => 'https://huggingface.co/bartowski/Dolphin3.0-Llama3.1-8B-GGUF/resolve/a6274707ba8c5f12d4521eabbce1b318bb2f27f5/Dolphin3.0-Llama3.1-8B-Q4_K_M.gguf',
            'sha256' => '268390e07edd407ad93ea21a868b7ae995b5950e01cad0db9e1802ae5049d405',
            'size_bytes' => 4920749472,
            'format' => 'gguf',
            'quantization' => 'Q4_K_M',
            'storage_class' => 'fixed_storage',
        ],
        'license' => 'Llama 3.1 Community License',
    ],
    'rootmonster-qwen3-14b-abliterated-gguf' => [
        'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'tools', 'cybersecurity', 'uncensored'],
        'features' => array_replace($unsupportedMediaFeatures, [
            'cybersecurity' => 'candidate',
        ]),
        'context_limit' => 32768,
        'artifact' => [
            'url' => 'https://huggingface.co/RootMonsteR/Qwen3-14B-Abliterated-GGUF/resolve/aad7bb258333fa83991acef39e31a97677eb711b/qwen3-14b-abliterated-Q4_K_M.gguf',
            'sha256' => 'c74b5bcfcf7d4c9386075cde43fd7a4580c602b46b90ad01d7fc58b696748bbb',
            'size_bytes' => 9001753792,
            'format' => 'gguf',
            'quantization' => 'Q4_K_M',
            'storage_class' => 'fixed_storage',
        ],
        'license' => 'Apache-2.0',
    ],
    'orcarouter-qwen3.8-27b-uncensored-q4-k-m' => [
        'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'tools', 'image_to_text', 'uncensored'],
        'features' => array_replace($unsupportedMediaFeatures, [
            'text_generation' => 'verified',
            'text_edit' => 'candidate',
            'tool_calling' => 'verified',
            'coding' => 'verified',
            'cybersecurity' => 'candidate',
            'image_to_text' => 'candidate',
            'uncensored' => 'verified',
        ]),
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
    'huihui-qwen3-30b-a3b-instruct-abliterated-gguf' => [
        'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'tools', 'uncensored'],
        'features' => $unsupportedMediaFeatures,
        'context_limit' => 32768,
        'artifact' => [
            'url' => 'https://huggingface.co/Sowkwndms/Huihui-Qwen3-30B-A3B-Instruct-2507-abliterated-Q4_K_M-GGUF/resolve/6972656944f13f6a156a881830c8726556e13143/huihui-qwen3-30b-a3b-instruct-2507-abliterated-q4_k_m.gguf',
            'sha256' => '8394a17f4fef88c92f0ade6237e198ecba74000ba38cae26d5a08148976c71be',
            'size_bytes' => 18556686496,
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
        'features' => $candidateMetadata[$id]['features'] ?? [
            'text_generation' => 'unknown',
            'text_edit' => 'unknown',
            'tool_calling' => 'unknown',
            'coding' => 'unknown',
            'cybersecurity' => 'unknown',
            'image_to_text' => 'unknown',
            'audio_input' => 'unknown',
            'audio_output' => 'unknown',
            'uncensored' => 'unknown',
        ],
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
        'preferred_model_id' => 'huihui-qwen3-30b-a3b-instruct-abliterated-gguf',
        'default_model_id' => 'huihui-qwen3-30b-a3b-instruct-abliterated-gguf',
        'fallback_model_ids' => [
            'orcarouter-qwen3.8-27b-uncensored-q4-k-m',
            'rootmonster-qwen3-14b-abliterated-gguf',
            'dolphin3-llama3.1-8b-gguf',
            'dolphin3-qwen2.5-3b-gguf',
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
