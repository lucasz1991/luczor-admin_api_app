<?php

namespace App\Data\Proxy;

final readonly class ProxyBodyReadResult
{
    public function __construct(
        public string $contents,
        public bool $limitExceeded,
        public bool $readFailed,
        public ?string $sha256,
    ) {}
}
