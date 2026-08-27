<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CogneePleskDeploymentTest extends TestCase
{
    public function test_plesk_override_publishes_cognee_only_on_loopback(): void
    {
        $override = file_get_contents(dirname(__DIR__, 2).'/../docker-compose.plesk-cognee.yml');

        $this->assertIsString($override);
        $this->assertStringContainsString('127.0.0.1:${COGNEE_HOST_PORT:-8010}:8000', $override);
        $this->assertStringNotContainsString('0.0.0.0:', $override);
    }

    public function test_standalone_plesk_memory_stack_keeps_all_host_ports_on_loopback(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.plesk-memory.yml');

        $this->assertIsString($compose);
        $this->assertStringContainsString('context: ./services/cognee', $compose);
        $this->assertStringContainsString('127.0.0.1:${REDIS_HOST_PORT:-6379}:6379', $compose);
        $this->assertStringContainsString('127.0.0.1:${COGNEE_HOST_PORT:-8010}:8000', $compose);
        $this->assertStringContainsString('internal: true', $compose);
        $this->assertStringContainsString('file: ./docker/secrets/cognee_llm_api_key', $compose);
        $this->assertStringContainsString('cognee-db-init:', $compose);
        $this->assertStringContainsString('POSTGRES_ADMIN_USER:', $compose);
        $this->assertStringContainsString('GRAPH_DATABASE_PROVIDER: kuzu', $compose);
        $this->assertStringNotContainsString('0.0.0.0:', $compose);
        $this->assertStringNotContainsString('graph-indexer:', $compose);
    }

    public function test_linux_provisioner_stores_the_key_atomically_without_printing_it(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/docker/provision-cognee.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('mktemp', $script);
        $this->assertStringContainsString('chmod 600', $script);
        $this->assertStringContainsString('mv -f "$temporary_file" "$api_key_file"', $script);
        $this->assertStringNotContainsString('echo "$api_key"', $script);
        $this->assertStringContainsString('docker-compose.plesk-memory.yml', $script);
        $this->assertStringContainsString('compose_exec exec -T cognee', $script);
    }
}
