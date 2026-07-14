<?php

namespace App\Services\Llm;

/** Upstream already emits canonical frames; hand lines through unchanged. */
class PassthroughStreamAdapter implements ProviderStreamAdapter
{
    public function feed(string $line): array
    {
        return $line === '' ? [] : [$line];
    }

    public function finish(): array
    {
        return [];
    }
}
