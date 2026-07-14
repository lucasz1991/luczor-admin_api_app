<?php

namespace App\Services\Llm;

/**
 * Stateful per-request SSE translator: upstream lines in, canonical
 * OpenAI-style `data: {...}` lines out (without trailing blank line).
 */
interface ProviderStreamAdapter
{
    /** @return array<int,string> */
    public function feed(string $line): array;

    /**
     * Called once at upstream EOF; returns closing canonical lines
     * (final finish frame and/or `data: [DONE]`) not yet emitted.
     *
     * @return array<int,string>
     */
    public function finish(): array;
}
