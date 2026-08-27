<?php

namespace App\Services;

class RedisHostKernelInspector
{
    private readonly bool $linuxHost;

    public function __construct(
        private readonly string $overcommitMemoryPath = '/proc/sys/vm/overcommit_memory',
        ?bool $linuxHost = null,
    ) {
        $this->linuxHost = $linuxHost ?? PHP_OS_FAMILY === 'Linux';
    }

    public function overcommitMemoryEnabled(): bool
    {
        if (! $this->linuxHost || ! is_readable($this->overcommitMemoryPath)) {
            return false;
        }

        $value = file_get_contents($this->overcommitMemoryPath);

        return is_string($value) && trim($value) === '1';
    }
}
