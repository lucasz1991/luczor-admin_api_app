<?php

namespace App\Services;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\ProviderCredential;
use App\Models\Setting;
use App\Models\UserPreference;

/**
 * Claude as the user-selected main model: admin switches, key resolution
 * (personal before organization) and the composer model list.
 *
 * The per-user key source and the provider-fallback option live in
 * user_preferences under server-owned keys that are deliberately NOT part of
 * UserPreference::ALLOWLIST, so the generic preferences API cannot touch them.
 */
class ClaudeAccessService
{
    public const PROVIDER = 'anthropic';

    public const REQUEST_FORMAT = 'messages';

    public const SOURCE_USER = 'user';

    public const SOURCE_ORGANIZATION = 'organization';

    public const SETTING_MODES_ALLOWED = 'claude_modes_allowed';

    public const SETTING_USER_KEYS_ALLOWED = 'claude_user_keys_allowed';

    public const SETTING_ORG_KEY_FOR_USERS = 'claude_org_key_for_users';

    /** @var array<string,array{default: bool, label: string}> */
    public const SETTINGS = [
        self::SETTING_MODES_ALLOWED => ['default' => false, 'label' => 'Claude-Modi im Desktop erlauben'],
        self::SETTING_USER_KEYS_ALLOWED => ['default' => true, 'label' => 'Persönliche Claude-Schlüssel erlauben'],
        self::SETTING_ORG_KEY_FOR_USERS => ['default' => false, 'label' => 'Organisationszugang für Benutzer freigeben'],
    ];

    public const PREFERENCE_SOURCE = 'claude_key_source';

    public const PREFERENCE_PROVIDER_FALLBACK = 'claude_provider_fallback';

    public const DEFAULT_EFFORTS = ['fast', 'balanced', 'thorough', 'max', 'ultra'];

    public function modesAllowed(): bool
    {
        return $this->setting(self::SETTING_MODES_ALLOWED);
    }

    public function userKeysAllowed(): bool
    {
        return $this->setting(self::SETTING_USER_KEYS_ALLOWED);
    }

    public function organizationKeyForUsers(): bool
    {
        return $this->setting(self::SETTING_ORG_KEY_FOR_USERS);
    }

    public function setting(string $key): bool
    {
        return (bool) Setting::getValue($key, self::SETTINGS[$key]['default'] ?? false);
    }

    public function putSetting(string $key, bool $value): void
    {
        Setting::putValue($key, $value, [
            'group' => 'claude',
            'label' => self::SETTINGS[$key]['label'],
            'type' => 'bool',
        ]);
    }

    public function globalCredential(): ?ProviderCredential
    {
        return ProviderCredential::query()
            ->global()
            ->where('provider', self::PROVIDER)
            ->where('request_format', self::REQUEST_FORMAT)
            ->where('active', true)
            ->orderBy('id')
            ->first();
    }

    public function personalCredential(int $userId): ?ProviderCredential
    {
        return ProviderCredential::query()
            ->ownedBy($userId)
            ->where('provider', self::PROVIDER)
            ->first();
    }

    /** @return array<int,string> */
    public function allowedSources(): array
    {
        $sources = [];
        if ($this->userKeysAllowed()) {
            $sources[] = self::SOURCE_USER;
        }
        if ($this->organizationKeyForUsers() && $this->globalCredential() !== null) {
            $sources[] = self::SOURCE_ORGANIZATION;
        }

        return $sources;
    }

    public function preferredSource(int $userId): ?string
    {
        $value = $this->preference($userId, self::PREFERENCE_SOURCE);

        return in_array($value, [self::SOURCE_USER, self::SOURCE_ORGANIZATION], true) ? $value : null;
    }

    public function setPreferredSource(int $userId, string $source): void
    {
        $this->putPreference($userId, self::PREFERENCE_SOURCE, $source);
    }

    public function providerFallback(int $userId): bool
    {
        return $this->preference($userId, self::PREFERENCE_PROVIDER_FALLBACK) === true;
    }

    public function setProviderFallback(int $userId, bool $enabled): void
    {
        $this->putPreference($userId, self::PREFERENCE_PROVIDER_FALLBACK, $enabled);
    }

    /**
     * Personal key first unless the user explicitly chose the organization
     * access; each source only counts while the admin allows it.
     *
     * @return array{source: ?string, credential: ?ProviderCredential}
     */
    public function resolve(int $userId): array
    {
        $personal = $this->userKeysAllowed() ? $this->personalCredential($userId) : null;
        if ($personal && (! $personal->active || trim((string) $personal->api_key) === '')) {
            $personal = null;
        }
        $organization = $this->organizationKeyForUsers() ? $this->globalCredential() : null;

        if ($organization && ($this->preferredSource($userId) === self::SOURCE_ORGANIZATION || ! $personal)) {
            return ['source' => self::SOURCE_ORGANIZATION, 'credential' => $organization];
        }
        if ($personal) {
            return ['source' => self::SOURCE_USER, 'credential' => $personal];
        }

        return ['source' => null, 'credential' => null];
    }

    /**
     * Wire shape of GET /api/v1/provider-keys/anthropic. masked_key only ever
     * describes the caller's own key, never the organization key.
     *
     * @return array<string,mixed>
     */
    public function status(int $userId): array
    {
        $resolved = $this->resolve($userId);
        $credential = $resolved['credential'];
        $personal = $this->personalCredential($userId);

        return [
            'source' => $resolved['source'],
            'connected' => $credential !== null,
            'masked_key' => $personal ? ($personal->maskedKey() ?: null) : null,
            'last_tested_at' => $credential?->last_tested_at?->toIso8601String(),
            'last_test_status' => $credential?->last_test_status,
            'allowed_sources' => $this->allowedSources(),
            'provider_fallback' => $this->providerFallback($userId),
        ];
    }

    /** Active profile of an active main-claude entry, or null. */
    public function allowedProfile(string $slug): ?ModelProfile
    {
        foreach ($this->activeEntries() as $entry) {
            if ($entry->modelProfile?->slug === $slug) {
                return $entry->modelProfile;
            }
        }

        return null;
    }

    /**
     * Composer model list: active + visible entries in admin order.
     *
     * @return array<int,array{id: string, label: string, description: string, efforts: array<int,string>}>
     */
    public function composerModels(): array
    {
        $models = [];
        foreach ($this->activeEntries() as $entry) {
            $profile = $entry->modelProfile;
            $meta = is_array($profile?->meta) ? $profile->meta : [];
            if (! $profile || ($meta['composer_visible'] ?? false) !== true) {
                continue;
            }
            $efforts = array_values(array_filter(
                is_array($meta['efforts'] ?? null) ? $meta['efforts'] : self::DEFAULT_EFFORTS,
                static fn (mixed $effort): bool => is_string($effort) && $effort !== '',
            ));
            $models[] = [
                'id' => (string) $profile->slug,
                'label' => (string) ($meta['composer_label'] ?? $profile->name),
                'description' => (string) ($meta['composer_description'] ?? ''),
                'efforts' => $efforts,
            ];
        }

        return $models;
    }

    /**
     * Wire shape of bootstrap routing.main_model.
     *
     * @return array<string,mixed>
     */
    public function bootstrapPayload(): array
    {
        $models = $this->composerModels();

        return [
            'claude_modes_allowed' => $this->modesAllowed(),
            'key_sources' => $this->allowedSources(),
            'default_model' => $models[0]['id'] ?? null,
            'models' => $models,
        ];
    }

    /** @return array<int,ModelUseCaseEntry> */
    private function activeEntries(): array
    {
        $useCase = ModelUseCase::query()
            ->where('slug', ProviderPolicyService::MAIN_USE_CASE)
            ->where('active', true)
            ->first();
        if (! $useCase) {
            return [];
        }

        return $useCase->entries()
            ->where('active', true)
            ->with('modelProfile')
            ->get()
            ->filter(fn (ModelUseCaseEntry $entry): bool => (bool) $entry->modelProfile?->active)
            ->values()
            ->all();
    }

    private function preference(int $userId, string $key): mixed
    {
        return UserPreference::query()->where('user_id', $userId)->where('key', $key)->value('value');
    }

    private function putPreference(int $userId, string $key, mixed $value): void
    {
        UserPreference::query()->updateOrCreate(['user_id' => $userId, 'key' => $key], ['value' => $value]);
    }
}
