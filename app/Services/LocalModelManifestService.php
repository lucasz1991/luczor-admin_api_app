<?php

namespace App\Services;

use App\Exceptions\LocalModelManifestConfigurationException;
use Illuminate\Support\Carbon;

final class LocalModelManifestService
{
    private const FLASH_MODEL_ID = 'qwen3.8-flash-next';

    private const FALLBACK_MODEL_ID = 'orcarouter-qwen3.8-27b-uncensored-q4-k-m';

    private const HASH_PATTERN = '/\A[a-f0-9]{64}\z/';

    private const ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,119}\z/';

    /** @return array<string,mixed> */
    public function envelope(bool $enforceProductionKeyPolicy = false): array
    {
        $payload = $this->payload();
        $canonical = $this->canonicalJson($payload);
        $signature = '';
        $key = $this->privateKey($enforceProductionKeyPolicy || app()->environment('production'));

        if (! openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new LocalModelManifestConfigurationException('local_model_manifest_signing_failed');
        }

        return [
            'key_id' => $this->keyId(),
            'algorithm' => 'RSA-SHA256',
            'payload_sha256' => hash('sha256', $canonical),
            'payload' => $payload,
            'signature' => base64_encode($signature),
        ];
    }

    /** @return array{url:string,schema_version:int,catalog_version:int,policy_version:int,key_id:string,available:bool} */
    public function discovery(): array
    {
        return [
            'url' => rtrim((string) config('luczor.api_prefix', '/api/v1'), '/').'/local-model/manifest',
            'schema_version' => $this->schemaVersion(),
            'catalog_version' => $this->version('catalog_version'),
            'policy_version' => $this->version('policy_version'),
            'key_id' => $this->keyId(),
            'available' => $this->signingKeyConfigured(),
        ];
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        if (config('local_models.catalog_override_valid') !== true) {
            throw new LocalModelManifestConfigurationException('local_model_catalog_json_invalid');
        }

        $models = $this->models();
        $now = Carbon::now('UTC');

        return [
            'schema_version' => $this->schemaVersion(),
            'catalog_version' => $this->version('catalog_version'),
            'policy_version' => $this->version('policy_version'),
            'generated_at' => $now->toIso8601String(),
            'expires_at' => $now->copy()->addSeconds($this->ttlSeconds())->toIso8601String(),
            'models' => $models,
            'routing' => $this->routing($models),
        ];
    }

    /** @param array<string,mixed> $payload */
    public function canonicalJson(array $payload): string
    {
        return json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private function schemaVersion(): int
    {
        $version = config('local_models.schema_version');
        if (! is_int($version) || $version !== 1) {
            throw new LocalModelManifestConfigurationException('local_model_schema_version_invalid');
        }

        return $version;
    }

    private function version(string $key): int
    {
        $version = config('local_models.'.$key);
        if (! is_int($version) || $version < 1) {
            throw new LocalModelManifestConfigurationException('local_model_'.$key.'_invalid');
        }

        return $version;
    }

    private function keyId(): string
    {
        $keyId = config('local_models.signing.key_id');
        if (! is_string($keyId) || ! preg_match(self::ID_PATTERN, $keyId)) {
            throw new LocalModelManifestConfigurationException('local_model_signing_key_id_invalid');
        }

        return $keyId;
    }

    private function ttlSeconds(): int
    {
        $ttl = config('local_models.ttl_seconds');
        if (! is_int($ttl) || $ttl < 300 || $ttl > 604800) {
            throw new LocalModelManifestConfigurationException('local_model_manifest_ttl_invalid');
        }

        return $ttl;
    }

    /** @return array<int,array<string,mixed>> */
    private function models(): array
    {
        $configured = config('local_models.models');
        if (! is_array($configured) || count($configured) !== 2 || ! array_is_list($configured)) {
            throw new LocalModelManifestConfigurationException('local_model_catalog_invalid');
        }

        $models = [];
        $ids = [];
        foreach ($configured as $model) {
            if (! is_array($model)) {
                throw new LocalModelManifestConfigurationException('local_model_entry_invalid');
            }
            $normalized = $this->model($model);
            if (isset($ids[$normalized['id']])) {
                throw new LocalModelManifestConfigurationException('local_model_id_duplicate');
            }
            $ids[$normalized['id']] = true;
            $models[] = $normalized;
        }

        $actualIds = array_keys($ids);
        $expectedIds = [self::FLASH_MODEL_ID, self::FALLBACK_MODEL_ID];
        sort($actualIds, SORT_STRING);
        sort($expectedIds, SORT_STRING);
        if ($actualIds !== $expectedIds) {
            throw new LocalModelManifestConfigurationException('local_model_catalog_unsupported');
        }

        return $models;
    }

    /**
     * @param  array<string,mixed>  $model
     * @return array<string,mixed>
     */
    private function model(array $model): array
    {
        $id = $this->requiredIdentifier($model['id'] ?? null, 'local_model_id_invalid');
        $displayName = $this->requiredString($model['display_name'] ?? null, 160, 'local_model_display_name_invalid');
        $executionTarget = $this->enum($model['execution_target'] ?? null, ['local_llama_cpp'], 'local_model_execution_target_invalid');
        $releaseChannel = $this->enum($model['release_channel'] ?? null, ['experimental', 'stable'], 'local_model_release_channel_invalid');
        $routingRole = $this->enum($model['routing_role'] ?? null, ['preferred', 'fallback'], 'local_model_routing_role_invalid');
        $promoted = $this->boolean($model['promoted'] ?? null, 'local_model_promoted_invalid');
        $enabled = $this->boolean($model['enabled'] ?? null, 'local_model_enabled_invalid');
        $capabilities = $this->stringList($model['capabilities'] ?? null, 20, 'local_model_capabilities_invalid');
        $contextLimit = $this->nullablePositiveInteger($model['context_limit'] ?? null, 'local_model_context_limit_invalid');
        $artifact = $this->artifact($model['artifact'] ?? null);
        $runtime = $this->runtime($model['runtime'] ?? null);
        $capacityPolicy = $this->capacityPolicy($model['capacity_policy'] ?? null);
        $healthPolicy = $this->healthPolicy($model['health_policy'] ?? null);
        $chatTemplateHash = $this->nullableHash($model['chat_template_hash'] ?? null, 'local_model_chat_template_hash_invalid');
        $evaluationReportHash = $this->nullableHash($model['evaluation_report_hash'] ?? null, 'local_model_evaluation_hash_invalid');
        $license = $this->nullableString($model['license'] ?? null, 160, 'local_model_license_invalid');

        $expectedRole = match ($id) {
            self::FLASH_MODEL_ID => 'preferred',
            self::FALLBACK_MODEL_ID => 'fallback',
            default => null,
        };
        if ($expectedRole !== null && $routingRole !== $expectedRole) {
            throw new LocalModelManifestConfigurationException('local_model_routing_role_invalid');
        }
        if (($promoted && $releaseChannel !== 'stable')
            || (! $promoted && $releaseChannel !== 'experimental')) {
            throw new LocalModelManifestConfigurationException('local_model_promotion_state_invalid');
        }
        // The atomic promotion/channel invariant above means promoted implies
        // stable. V1 additionally requires the Orca fallback to stay promoted.
        if ($id === self::FALLBACK_MODEL_ID && ! $promoted) {
            throw new LocalModelManifestConfigurationException('local_model_fallback_state_invalid');
        }

        if ($enabled && (
            $artifact === null
            || $runtime === null
            || $contextLimit === null
            || $capacityPolicy['min_total_ram_bytes'] === null
            || $capacityPolicy['min_available_ram_bytes'] === null
            || $capacityPolicy['min_vram_bytes'] === null
            || $capacityPolicy['min_storage_free_bytes'] === null
            || $capacityPolicy['max_startup_seconds'] === null
            || $capacityPolicy['benchmark_thresholds'] === null
            || $chatTemplateHash === null
            || $evaluationReportHash === null
            || $license === null
        )) {
            throw new LocalModelManifestConfigurationException('local_model_enabled_metadata_incomplete');
        }

        return [
            'id' => $id,
            'display_name' => $displayName,
            'execution_target' => $executionTarget,
            'release_channel' => $releaseChannel,
            'routing_role' => $routingRole,
            'promoted' => $promoted,
            'enabled' => $enabled,
            'capabilities' => $capabilities,
            'context_limit' => $contextLimit,
            'artifact' => $artifact,
            'runtime' => $runtime,
            'capacity_policy' => $capacityPolicy,
            'health_policy' => $healthPolicy,
            'chat_template_hash' => $chatTemplateHash,
            'evaluation_report_hash' => $evaluationReportHash,
            'license' => $license,
        ];
    }

    /** @return array<string,mixed>|null */
    private function artifact(mixed $artifact): ?array
    {
        if ($artifact === null) {
            return null;
        }
        if (! is_array($artifact)) {
            throw new LocalModelManifestConfigurationException('local_model_artifact_invalid');
        }

        $url = $this->requiredString($artifact['url'] ?? null, 2048, 'local_model_artifact_url_invalid');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new LocalModelManifestConfigurationException('local_model_artifact_url_invalid');
        }

        return [
            'url' => $url,
            'sha256' => $this->requiredHash($artifact['sha256'] ?? null, 'local_model_artifact_hash_invalid'),
            'size_bytes' => $this->positiveInteger($artifact['size_bytes'] ?? null, 'local_model_artifact_size_invalid'),
            'format' => $this->enum($artifact['format'] ?? null, ['gguf'], 'local_model_artifact_format_invalid'),
            'quantization' => $this->requiredIdentifier($artifact['quantization'] ?? null, 'local_model_artifact_quantization_invalid'),
            'storage_class' => $this->enum(
                $artifact['storage_class'] ?? null,
                ['fixed_nvme_required', 'fixed_storage'],
                'local_model_artifact_storage_class_invalid',
            ),
        ];
    }

    /** @return array<string,mixed>|null */
    private function runtime(mixed $runtime): ?array
    {
        if ($runtime === null) {
            return null;
        }
        if (! is_array($runtime)) {
            throw new LocalModelManifestConfigurationException('local_model_runtime_invalid');
        }

        $minimum = $this->positiveInteger($runtime['min_context_tokens'] ?? null, 'local_model_runtime_context_invalid');
        $maximum = $this->positiveInteger($runtime['max_context_tokens'] ?? null, 'local_model_runtime_context_invalid');
        if ($minimum > $maximum) {
            throw new LocalModelManifestConfigurationException('local_model_runtime_context_invalid');
        }

        return [
            'id' => $this->enum($runtime['id'] ?? null, ['llama.cpp'], 'local_model_runtime_id_invalid'),
            'version' => $this->requiredIdentifier($runtime['version'] ?? null, 'local_model_runtime_version_invalid'),
            'sha256' => $this->requiredHash($runtime['sha256'] ?? null, 'local_model_runtime_hash_invalid'),
            'min_context_tokens' => $minimum,
            'max_context_tokens' => $maximum,
        ];
    }

    /** @return array<string,mixed> */
    private function capacityPolicy(mixed $policy): array
    {
        if (! is_array($policy)) {
            throw new LocalModelManifestConfigurationException('local_model_capacity_policy_invalid');
        }

        $benchmarks = $policy['benchmark_thresholds'] ?? null;
        if ($benchmarks !== null && ! is_array($benchmarks)) {
            throw new LocalModelManifestConfigurationException('local_model_benchmark_policy_invalid');
        }

        return [
            'min_total_ram_bytes' => $this->nullablePositiveInteger($policy['min_total_ram_bytes'] ?? null, 'local_model_capacity_policy_invalid'),
            'min_available_ram_bytes' => $this->nullablePositiveInteger($policy['min_available_ram_bytes'] ?? null, 'local_model_capacity_policy_invalid'),
            'min_vram_bytes' => $this->nullablePositiveInteger($policy['min_vram_bytes'] ?? null, 'local_model_capacity_policy_invalid'),
            'min_storage_free_bytes' => $this->nullablePositiveInteger($policy['min_storage_free_bytes'] ?? null, 'local_model_capacity_policy_invalid'),
            'max_startup_seconds' => $this->nullablePositiveInteger($policy['max_startup_seconds'] ?? null, 'local_model_capacity_policy_invalid'),
            'benchmark_thresholds' => $benchmarks === null ? null : [
                'min_prefill_tokens_per_second' => $this->positiveInteger($benchmarks['min_prefill_tokens_per_second'] ?? null, 'local_model_benchmark_policy_invalid'),
                'min_decode_tokens_per_second' => $this->positiveInteger($benchmarks['min_decode_tokens_per_second'] ?? null, 'local_model_benchmark_policy_invalid'),
                'max_first_token_ms' => $this->positiveInteger($benchmarks['max_first_token_ms'] ?? null, 'local_model_benchmark_policy_invalid'),
            ],
        ];
    }

    /** @return array{cooldown_ms:int,max_consecutive_failures:int} */
    private function healthPolicy(mixed $policy): array
    {
        if (! is_array($policy)) {
            throw new LocalModelManifestConfigurationException('local_model_health_policy_invalid');
        }

        $cooldownMs = $this->positiveInteger($policy['cooldown_ms'] ?? null, 'local_model_health_policy_invalid');
        if ($cooldownMs < 1000) {
            throw new LocalModelManifestConfigurationException('local_model_health_policy_invalid');
        }

        return [
            'cooldown_ms' => $cooldownMs,
            'max_consecutive_failures' => $this->positiveInteger($policy['max_consecutive_failures'] ?? null, 'local_model_health_policy_invalid'),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $models
     * @return array<string,mixed>
     */
    private function routing(array $models): array
    {
        $routing = config('local_models.routing');
        if (! is_array($routing)) {
            throw new LocalModelManifestConfigurationException('local_model_routing_invalid');
        }

        $byId = collect($models)->keyBy('id');
        $preferred = $this->requiredIdentifier($routing['preferred_model_id'] ?? null, 'local_model_preferred_id_invalid');
        $preferredModel = $byId->get($preferred);
        if (($preferredModel['routing_role'] ?? null) !== 'preferred') {
            throw new LocalModelManifestConfigurationException('local_model_preferred_id_invalid');
        }

        $fallbacks = $this->stringList($routing['fallback_model_ids'] ?? null, 19, 'local_model_fallback_ids_invalid');
        foreach ($fallbacks as $fallback) {
            if (($byId->get($fallback)['routing_role'] ?? null) !== 'fallback') {
                throw new LocalModelManifestConfigurationException('local_model_fallback_ids_invalid');
            }
        }

        $configuredDefault = $this->requiredIdentifier($routing['default_model_id'] ?? null, 'local_model_default_id_invalid');
        $default = ($preferredModel['promoted'] ?? null) === true ? $preferred : $configuredDefault;
        if (($byId->get($default)['promoted'] ?? null) !== true
            || ($default !== $preferred && ! in_array($default, $fallbacks, true))) {
            throw new LocalModelManifestConfigurationException('local_model_default_id_invalid');
        }

        $experimental = $this->stringListAllowEmpty(
            $routing['experimental_model_ids'] ?? null,
            19,
            'local_model_experimental_ids_invalid',
        );
        foreach ($experimental as $modelId) {
            $model = $byId->get($modelId);
            if (($model['release_channel'] ?? null) !== 'experimental' || ($model['promoted'] ?? null) !== false) {
                throw new LocalModelManifestConfigurationException('local_model_experimental_ids_invalid');
            }
        }
        $expectedExperimental = collect($models)
            ->filter(fn (array $model): bool => $model['release_channel'] === 'experimental' && $model['promoted'] === false)
            ->pluck('id')
            ->values()
            ->all();
        if ($experimental !== $expectedExperimental) {
            throw new LocalModelManifestConfigurationException('local_model_experimental_ids_invalid');
        }

        $requiredState = $this->stringList($routing['required_local_state'] ?? null, 20, 'local_model_required_state_invalid');
        foreach (['model_enabled', 'artifact_verified', 'runtime_verified', 'capacity_qualified', 'health_eligible'] as $required) {
            if (! in_array($required, $requiredState, true)) {
                throw new LocalModelManifestConfigurationException('local_model_required_state_invalid');
            }
        }

        return [
            'strategy' => $this->enum($routing['strategy'] ?? null, ['local_first'], 'local_model_routing_strategy_invalid'),
            'local_first' => $this->requiredTrue($routing['local_first'] ?? null, 'local_model_local_first_invalid'),
            'preferred_model_id' => $preferred,
            'default_model_id' => $default,
            'fallback_model_ids' => $fallbacks,
            'experimental_model_ids' => $experimental,
            'experimental_opt_in_required' => $this->requiredTrue(
                $routing['experimental_opt_in_required'] ?? null,
                'local_model_experimental_opt_in_invalid',
            ),
            'external_execution_target' => $this->enum(
                $routing['external_execution_target'] ?? null,
                ['laravel_proxy'],
                'local_model_external_target_invalid',
            ),
            'external_allowed' => $this->boolean($routing['external_allowed'] ?? null, 'local_model_external_allowed_invalid'),
            'external_requires_explicit_approval' => $this->requiredTrue(
                $routing['external_requires_explicit_approval'] ?? null,
                'local_model_external_approval_invalid',
            ),
            'no_silent_external_fallback' => $this->requiredTrue(
                $routing['no_silent_external_fallback'] ?? null,
                'local_model_silent_fallback_invalid',
            ),
            'required_local_state' => $requiredState,
            'decision_reasons' => $this->stringList($routing['decision_reasons'] ?? null, 20, 'local_model_decision_reasons_invalid'),
            'egress_policy_version' => $this->requiredIdentifier(
                $routing['egress_policy_version'] ?? null,
                'local_model_egress_policy_version_invalid',
            ),
        ];
    }

    private function privateKey(bool $enforceProductionKeyPolicy = false): \OpenSSLAsymmetricKey
    {
        $configured = trim((string) config('local_models.signing.private_key', ''));
        $path = trim((string) config('local_models.signing.private_key_file', ''));
        if ($enforceProductionKeyPolicy && (
            $configured !== ''
            || $path === ''
            || ! $this->absolutePath($path)
            || $this->insideCheckout($path)
        )) {
            throw new LocalModelManifestConfigurationException('local_model_signing_key_path_unsafe');
        }
        if ($path !== '') {
            $resolved = $this->absolutePath($path) ? $path : base_path($path);
            if (! is_readable($resolved)) {
                throw new LocalModelManifestConfigurationException('local_model_signing_key_unreadable');
            }
            $realPath = realpath($resolved);
            if ($realPath === false) {
                throw new LocalModelManifestConfigurationException('local_model_signing_key_unreadable');
            }
            if ($enforceProductionKeyPolicy && $this->insideCheckout($realPath)) {
                throw new LocalModelManifestConfigurationException('local_model_signing_key_path_unsafe');
            }
            $contents = file_get_contents($resolved);
            if ($contents === false) {
                throw new LocalModelManifestConfigurationException('local_model_signing_key_unreadable');
            }
            $configured = $contents;
        }

        $key = $configured === '' ? false : openssl_pkey_get_private($configured);
        if (! $key instanceof \OpenSSLAsymmetricKey) {
            throw new LocalModelManifestConfigurationException('local_model_signing_key_invalid');
        }

        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 2048) {
            throw new LocalModelManifestConfigurationException('local_model_signing_key_unsafe');
        }
        $this->assertExpectedPublicKeyPin($details, $enforceProductionKeyPolicy);

        return $key;
    }

    private function signingKeyConfigured(): bool
    {
        try {
            $this->privateKey(app()->environment('production'));

            return true;
        } catch (LocalModelManifestConfigurationException) {
            return false;
        }
    }

    private function absolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /** @param array<string,mixed> $details */
    private function assertExpectedPublicKeyPin(array $details, bool $required): void
    {
        $expected = trim((string) config('local_models.signing.expected_public_key_sha256', ''));
        if ($expected === '') {
            if ($required) {
                throw new LocalModelManifestConfigurationException('local_model_signing_public_key_pin_missing');
            }

            return;
        }
        if (! preg_match(self::HASH_PATTERN, $expected) || ! is_string($details['key'] ?? null)) {
            throw new LocalModelManifestConfigurationException('local_model_signing_public_key_pin_invalid');
        }
        if (! hash_equals($expected, hash('sha256', $details['key']))) {
            throw new LocalModelManifestConfigurationException('local_model_signing_public_key_mismatch');
        }
    }

    private function insideCheckout(string $path): bool
    {
        $base = realpath(base_path()) ?: base_path();
        $candidate = realpath($path) ?: $path;
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $base = strtolower($base);
            $candidate = strtolower($candidate);
        }

        return $candidate === $base || str_starts_with($candidate, $base.'/');
    }

    private function requiredIdentifier(mixed $value, string $code): string
    {
        if (! is_string($value) || ! preg_match(self::ID_PATTERN, $value)) {
            throw new LocalModelManifestConfigurationException($code);
        }

        return $value;
    }

    private function requiredString(mixed $value, int $maxLength, string $code): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw new LocalModelManifestConfigurationException($code);
        }

        return $value;
    }

    private function nullableString(mixed $value, int $maxLength, string $code): ?string
    {
        return $value === null ? null : $this->requiredString($value, $maxLength, $code);
    }

    /** @param array<int,string> $allowed */
    private function enum(mixed $value, array $allowed, string $code): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new LocalModelManifestConfigurationException($code);
        }

        return $value;
    }

    private function boolean(mixed $value, string $code): bool
    {
        if (! is_bool($value)) {
            throw new LocalModelManifestConfigurationException($code);
        }

        return $value;
    }

    private function requiredTrue(mixed $value, string $code): bool
    {
        if ($this->boolean($value, $code) !== true) {
            throw new LocalModelManifestConfigurationException($code);
        }

        return true;
    }

    private function positiveInteger(mixed $value, string $code): int
    {
        if (! is_int($value) || $value < 1) {
            throw new LocalModelManifestConfigurationException($code);
        }

        return $value;
    }

    private function nullablePositiveInteger(mixed $value, string $code): ?int
    {
        return $value === null ? null : $this->positiveInteger($value, $code);
    }

    private function requiredHash(mixed $value, string $code): string
    {
        if (! is_string($value) || ! preg_match(self::HASH_PATTERN, $value)) {
            throw new LocalModelManifestConfigurationException($code);
        }

        return $value;
    }

    private function nullableHash(mixed $value, string $code): ?string
    {
        return $value === null ? null : $this->requiredHash($value, $code);
    }

    /** @return array<int,string> */
    private function stringList(mixed $value, int $maxItems, string $code): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > $maxItems) {
            throw new LocalModelManifestConfigurationException($code);
        }
        $strings = [];
        foreach ($value as $item) {
            $strings[] = $this->requiredIdentifier($item, $code);
        }

        return array_values(array_unique($strings));
    }

    /** @return array<int,string> */
    private function stringListAllowEmpty(mixed $value, int $maxItems, string $code): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maxItems) {
            throw new LocalModelManifestConfigurationException($code);
        }
        if ($value === []) {
            return [];
        }

        return $this->stringList($value, $maxItems, $code);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
