<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CogneePleskDeploymentTest extends TestCase
{
    public function test_cognee_runtime_is_pinned_to_the_audited_1_4_2_manifest(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/services/cognee/Dockerfile');

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString(
            'FROM cognee/cognee:1.4.2@sha256:680cd87732430cf5e52b1ff58ae7ec2ed6ed71a9a32a9e77b8a0627f425cc4c3',
            $dockerfile,
        );
        $this->assertStringNotContainsString('cognee/cognee:1.4.0', $dockerfile);
    }

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
        $this->assertStringContainsString('cognee-loopback:', $compose);
        $this->assertStringContainsString('127.0.0.1:${COGNEE_HOST_PORT:-8010}:8080', $compose);
        $this->assertStringContainsString('./docker/nginx/cognee-loopback.conf:/etc/nginx/luczor-loopback.conf:ro', $compose);
        $this->assertStringContainsString('internal: true', $compose);
        $this->assertStringContainsString('LLM_PROVIDER: ollama', $compose);
        $this->assertStringContainsString('EMBEDDING_PROVIDER: fastembed', $compose);
        $this->assertStringContainsString('cognee-db-init:', $compose);
        $this->assertStringContainsString('POSTGRES_ADMIN_USER:', $compose);
        $this->assertStringContainsString('GRAPH_DATABASE_PROVIDER: kuzu', $compose);
        $this->assertStringContainsString('DEFAULT_USER_EMAIL: cognee-service@luczor.follow-flow.de', $compose);
        $this->assertStringNotContainsString('@invalid.local', $compose);
        $this->assertStringNotContainsString('- "0.0.0.0:', $compose);
        $this->assertStringNotContainsString('graph-indexer:', $compose);
        $this->assertMatchesRegularExpression(
            '/cognee:\R.*?networks:\R\s+- data\R\s+- inference\R.*?cognee-loopback:/s',
            $compose,
        );
        $this->assertMatchesRegularExpression(
            '/cognee-loopback:\R.*?networks:\R\s+- data\R\s+- loopback-publish/s',
            $compose,
        );
    }

    public function test_postgres_wrapper_keeps_the_upstream_server_command(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.plesk-memory.yml');

        $this->assertIsString($compose);
        $this->assertMatchesRegularExpression(
            '/postgres:\R\s+image: pgvector\/pgvector:pg16.*?entrypoint:.*?command:\R\s+- postgres/s',
            $compose,
        );
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
        $this->assertStringContainsString('cognee-service@luczor.follow-flow.de', $script);
        $this->assertStringNotContainsString('@invalid.local', $script);
    }
}
