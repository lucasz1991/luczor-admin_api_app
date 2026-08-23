<?php

namespace App\Services\Llm;

use App\Models\ProviderCredential;

/**
 * Resolves the wire driver for a provider. A credential may force the
 * OpenAI-compatible wire via meta.wire = 'chat_completions' (e.g. Azure
 * or OpenAI-compatible gateways registered under provider 'openai').
 */
class ProviderDriverRegistry
{
    public function for(string $provider, ?ProviderCredential $credential = null): ProviderDriver
    {
        $meta = is_array($credential?->meta) ? $credential->meta : [];
        if (($meta['wire'] ?? null) === 'chat_completions') {
            return new OpenAiCompatDriver($provider);
        }

        return match ($provider) {
            'anthropic' => new AnthropicMessagesDriver,
            'openai' => new OpenAiResponsesDriver,
            default => new OpenAiCompatDriver($provider),
        };
    }
}
