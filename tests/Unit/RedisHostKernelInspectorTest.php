<?php

namespace Tests\Unit;

use App\Services\RedisHostKernelInspector;
use PHPUnit\Framework\TestCase;

class RedisHostKernelInspectorTest extends TestCase
{
    public function test_linux_requires_overcommit_memory_value_one(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'luczor-overcommit-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, "0\n");
            $this->assertFalse((new RedisHostKernelInspector($path, true))->overcommitMemoryEnabled());

            file_put_contents($path, "1\n");
            $this->assertTrue((new RedisHostKernelInspector($path, true))->overcommitMemoryEnabled());
        } finally {
            @unlink($path);
        }
    }

    public function test_missing_kernel_value_fails_closed(): void
    {
        $this->assertFalse(
            (new RedisHostKernelInspector(__DIR__.'/missing-overcommit-memory', true))->overcommitMemoryEnabled()
        );
    }

    public function test_non_linux_host_fails_closed_even_if_the_file_contains_one(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'luczor-overcommit-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, "1\n");
            $this->assertFalse((new RedisHostKernelInspector($path, false))->overcommitMemoryEnabled());
        } finally {
            @unlink($path);
        }
    }
}
