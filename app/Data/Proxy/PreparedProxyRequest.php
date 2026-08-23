<?php

namespace App\Data\Proxy;

use App\Models\ModelUseCase;

final readonly class PreparedProxyRequest
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $meta
     * @param  array<int,string>  $requiredCapabilities
     */
    public function __construct(
        public array $payload,
        public array $meta,
        public string $taskType,
        public ?ModelUseCase $useCase,
        public array $requiredCapabilities,
    ) {}
}
