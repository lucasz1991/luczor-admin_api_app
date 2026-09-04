<?php

$rawCatalog = trim((string) env('LUCZOR_LOCAL_MODEL_CATALOG_JSON', ''));
$catalogOverride = $rawCatalog === '' ? null : json_decode($rawCatalog, true);

return [
    'schema_version' => 1,
    'catalog_version' => (int) env('LUCZOR_LOCAL_MODEL_CATALOG_VERSION', 2026083001),
    'policy_version' => (int) env('LUCZOR_LOCAL_MODEL_POLICY_VERSION', 2026083001),
    'ttl_seconds' => max(300, (int) env('LUCZOR_LOCAL_MODEL_MANIFEST_TTL_SECONDS', 86400)),
    'signing' => [
        // Keep this key lifecycle separate from provider, device-job and Voice keys.
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
    'models' => is_array($catalogOverride['models'] ?? null) ? $catalogOverride['models'] : [
        [
            'id' => 'qwen3.8-flash-next',
            'display_name' => 'Qwen3.8 Flash-Next',
            'execution_target' => 'local_llama_cpp',
            'release_channel' => 'experimental',
            'routing_role' => 'preferred',
            'promoted' => false,
            // An experimental catalog entry is not executable until an
            // operator supplies and signs complete artifact/runtime metadata.
            'enabled' => false,
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation'],
            'context_limit' => null,
            'artifact' => null,
            'runtime' => null,
            'capacity_policy' => [
                'min_total_ram_bytes' => null,
                'min_available_ram_bytes' => null,
                'min_vram_bytes' => null,
                'min_storage_free_bytes' => null,
                'max_startup_seconds' => null,
                'benchmark_thresholds' => null,
            ],
            'health_policy' => [
                'cooldown_ms' => 300000,
                'max_consecutive_failures' => 2,
            ],
            'chat_template_hash' => null,
            'evaluation_report_hash' => null,
            'license' => null,
        ],
        [
            'id' => 'orcarouter-qwen3.8-27b-uncensored-q4-k-m',
            'display_name' => 'OrcaRouter Qwen3.8-27B Uncensored Q4_K_M',
            'execution_target' => 'local_llama_cpp',
            'release_channel' => 'stable',
            'routing_role' => 'fallback',
            'promoted' => true,
            // Metadata-only by default: Laravel neither hosts nor executes the
            // model and no unverifiable URL/hash is invented here.
            'enabled' => false,
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation'],
            'context_limit' => null,
            'artifact' => null,
            'runtime' => null,
            'capacity_policy' => [
                'min_total_ram_bytes' => null,
                'min_available_ram_bytes' => null,
                'min_vram_bytes' => null,
                'min_storage_free_bytes' => null,
                'max_startup_seconds' => null,
                'benchmark_thresholds' => null,
            ],
            'health_policy' => [
                'cooldown_ms' => 300000,
                'max_consecutive_failures' => 2,
            ],
            'chat_template_hash' => null,
            'evaluation_report_hash' => null,
            'license' => null,
        ],
    ],
    'routing' => is_array($catalogOverride['routing'] ?? null) ? $catalogOverride['routing'] : [
        'strategy' => 'local_first',
        'local_first' => true,
        'preferred_model_id' => 'qwen3.8-flash-next',
        'default_model_id' => 'orcarouter-qwen3.8-27b-uncensored-q4-k-m',
        'fallback_model_ids' => ['orcarouter-qwen3.8-27b-uncensored-q4-k-m'],
        'experimental_model_ids' => ['qwen3.8-flash-next'],
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
