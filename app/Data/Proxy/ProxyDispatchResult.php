<?php

namespace App\Data\Proxy;

use App\Models\LlmAttempt;
use App\Models\ModelProfile;
use App\Models\ProviderCredential;
use App\Services\Llm\ProviderDriver;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class ProxyDispatchResult
{
    private function __construct(
        public ?ResponseInterface $upstream,
        public ?LlmAttempt $attempt,
        public ?ModelProfile $profile,
        public ?ProviderCredential $credential,
        public ?ProviderDriver $driver,
        public ?float $startedAt,
        public ?int $connectMs,
        public ?Response $failureResponse,
    ) {}

    public static function winner(
        ResponseInterface $upstream,
        LlmAttempt $attempt,
        ModelProfile $profile,
        ProviderCredential $credential,
        ProviderDriver $driver,
        float $startedAt,
        int $connectMs,
    ): self {
        return new self($upstream, $attempt, $profile, $credential, $driver, $startedAt, $connectMs, null);
    }

    public static function failure(Response $response): self
    {
        return new self(null, null, null, null, null, null, null, $response);
    }

    public function succeeded(): bool
    {
        return $this->failureResponse === null
            && $this->upstream !== null
            && $this->attempt !== null
            && $this->profile !== null
            && $this->credential !== null
            && $this->driver !== null
            && $this->startedAt !== null
            && $this->connectMs !== null;
    }
}
