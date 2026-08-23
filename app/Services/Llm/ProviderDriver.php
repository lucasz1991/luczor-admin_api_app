<?php

namespace App\Services\Llm;

/**
 * SOLL §2 / P2b — translates between the canonical wire (OpenAI chat
 * completions, spoken by the client) and a provider's native wire.
 */
interface ProviderDriver
{
    public function defaultBaseUrl(): string;

    public function endpoint(string $baseUrl): string;

    /** @return array<string,string> */
    public function headers(string $apiKey, bool $stream): array;

    /**
     * Canonical payload (messages/tools/model/temperature/max_tokens/stream)
     * to the provider's request body.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function buildBody(array $payload): array;

    /**
     * Provider response JSON to the canonical chat.completion shape.
     *
     * @param  array<string,mixed>  $json
     * @return array<string,mixed>
     */
    public function normalizeResponse(array $json): array;

    /** True when the upstream already speaks the canonical wire byte-for-byte. */
    public function passthrough(): bool;

    /** Fresh stateful SSE translator; only consulted when !passthrough(). */
    public function streamAdapter(): ProviderStreamAdapter;
}
