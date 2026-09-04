<?php

namespace App\Services\Llm;

use App\Models\ProviderCredential;
use InvalidArgumentException;

/**
 * Resolves only explicitly configured provider/wire pairs. Legacy meta.wire
 * values are migrated into request_format and are never consulted at runtime.
 */
class ProviderDriverRegistry
{
    public function for(string $provider, ?ProviderCredential $credential = null): ProviderDriver
    {
        if (! $credential || $credential->provider !== $provider) {
            throw new InvalidArgumentException('A matching provider credential is required.');
        }

        return match ([$provider, $credential->request_format]) {
            ['openrouter', 'chat_completions'] => new OpenAiCompatDriver('openrouter'),
            ['openai', 'chat_completions'] => new OpenAiCompatDriver('openai'),
            ['openai', 'responses'] => new OpenAiResponsesDriver,
            ['anthropic', 'messages'] => new AnthropicMessagesDriver,
            default => throw new InvalidArgumentException('The provider wire format is not supported.'),
        };
    }
}
