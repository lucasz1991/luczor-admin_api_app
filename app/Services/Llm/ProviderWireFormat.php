<?php

namespace App\Services\Llm;

use App\Models\ModelProfile;
use App\Models\ProviderCredential;

final class ProviderWireFormat
{
    /** @return array<int,string> */
    public static function allowedFor(string $provider): array
    {
        return match ($provider) {
            'openrouter' => ['chat_completions'],
            'openai' => ['responses', 'chat_completions'],
            'anthropic' => ['messages'],
            default => [],
        };
    }

    public static function defaultFor(string $provider): ?string
    {
        return match ($provider) {
            'openrouter' => 'chat_completions',
            'openai' => 'responses',
            'anthropic' => 'messages',
            default => null,
        };
    }

    public static function isCompatible(ModelProfile $profile, ProviderCredential $credential): bool
    {
        $baseUrl = trim((string) $credential->base_url);

        return $credential->active
            && $credential->provider === $profile->provider
            && is_string($credential->request_format)
            && in_array($credential->request_format, self::allowedFor($profile->provider), true)
            && trim((string) $credential->api_key) !== ''
            && ($baseUrl === '' || parse_url($baseUrl, PHP_URL_SCHEME) === 'https');
    }
}
