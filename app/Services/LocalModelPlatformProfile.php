<?php

namespace App\Services;

use App\Exceptions\LocalModelManifestConfigurationException;

/** Select before validation/signing; model weights and stable tier identity never change. */
final class LocalModelPlatformProfile
{
    public const TARGETS = ['windows-x86_64', 'linux-x86_64', 'linux-aarch64', 'macos-aarch64', 'macos-x86_64'];

    private const FIELDS = ['runtime', 'capacity_policy', 'context_limit', 'chat_template_hash', 'evaluation_report_hash'];

    public function select(array $model, ?string $target): array
    {
        if ($target !== null && ! in_array($target, self::TARGETS, true)) {
            throw new LocalModelManifestConfigurationException('local_model_platform_invalid');
        }
        $variants = $model['platform_profiles'] ?? [];
        if (! is_array($variants)) {
            throw new LocalModelManifestConfigurationException('local_model_platform_profiles_invalid');
        }
        foreach ($variants as $platform => $profile) {
            if (! in_array($platform, self::TARGETS, true) || ! is_array($profile)
                || array_diff(array_keys($profile), self::FIELDS) !== []
                || ! is_array($profile['runtime'] ?? null)) {
                throw new LocalModelManifestConfigurationException('local_model_platform_profiles_invalid');
            }
        }
        unset($model['platform_profiles']);
        if ($target === null || $variants === []) {
            return $model; // Legacy catalog semantics remain unchanged.
        }
        if (! isset($variants[$target])) {
            $model['enabled'] = false;
            $model['runtime'] = null;

            return $model;
        }

        return array_replace($model, $variants[$target]);
    }
}
