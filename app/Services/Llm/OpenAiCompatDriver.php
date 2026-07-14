<?php

namespace App\Services\Llm;

/**
 * OpenRouter and any /chat/completions-compatible endpoint. The upstream
 * already speaks the canonical wire, so responses pass through untouched.
 */
class OpenAiCompatDriver implements ProviderDriver
{
    public function __construct(private string $provider = 'openrouter') {}

    public function defaultBaseUrl(): string
    {
        return $this->provider === 'openai' ? 'https://api.openai.com/v1' : 'https://openrouter.ai/api/v1';
    }

    public function endpoint(string $baseUrl): string
    {
        return $baseUrl.'/chat/completions';
    }

    public function headers(string $apiKey, bool $stream): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
        ];
        if ($this->provider === 'openrouter') {
            $headers['HTTP-Referer'] = (string) config('app.url');
            $headers['X-OpenRouter-Title'] = 'Luczor';
        }

        return $headers;
    }

    public function buildBody(array $payload): array
    {
        // OpenRouter models may reason internally by default. Keep that
        // reasoning available to the model, but never stream it into the
        // user-visible assistant response.
        if ($this->provider === 'openrouter') {
            $payload['reasoning'] = ['exclude' => true];
        } else {
            unset($payload['reasoning']);
        }

        return $payload;
    }

    public function normalizeResponse(array $json): array
    {
        return $json;
    }

    public function passthrough(): bool
    {
        return true;
    }

    public function streamAdapter(): ProviderStreamAdapter
    {
        return new PassthroughStreamAdapter();
    }
}
