<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CogneeLocalProviderDeploymentTest extends TestCase
{
    public function test_plesk_runtime_uses_only_local_inference_providers(): void
    {
        $compose = $this->readProjectFile('docker-compose.plesk-memory.yml');

        $this->assertStringContainsString('LLM_PROVIDER: ollama', $compose);
        $this->assertStringContainsString('LLM_ENDPOINT: http://ollama:11434/v1', $compose);
        $this->assertStringContainsString('EMBEDDING_PROVIDER: fastembed', $compose);
        $this->assertStringContainsString('EMBEDDING_MODEL: sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2', $compose);
        $this->assertStringContainsString('EMBEDDING_DIMENSIONS: "384"', $compose);
        $this->assertStringContainsString('HF_HUB_OFFLINE: "1"', $compose);
        $this->assertStringNotContainsString('LLM_PROVIDER: ${COGNEE_LLM_PROVIDER:-openai}', $compose);
        $this->assertStringNotContainsString('openai/text-embedding', $compose);
        $this->assertStringNotContainsString('- egress', $compose);
        $this->assertStringNotContainsString('file: ./docker/secrets/cognee_llm_api_key', $compose);
        $this->assertStringNotContainsString('file: ./docker/secrets/cognee_embedding_api_key', $compose);
    }

    public function test_ollama_runtime_is_internal_bounded_and_cloud_disabled(): void
    {
        $compose = $this->readProjectFile('docker-compose.plesk-memory.yml');

        $this->assertStringContainsString('ollama/ollama:0.33.1@sha256:075246f72d4109385b4a01c3ac8e9cbd26a0bcb21cd7aa30edbccd24e1b3180c', $compose);
        $this->assertStringContainsString('OLLAMA_NO_CLOUD: "1"', $compose);
        $this->assertStringContainsString('OLLAMA_MAX_LOADED_MODELS: "1"', $compose);
        $this->assertStringContainsString('OLLAMA_NUM_PARALLEL: "1"', $compose);
        $this->assertStringContainsString('mem_limit: ${LUCZOR_OLLAMA_MEMORY_LIMIT:-4g}', $compose);
        $this->assertStringContainsString("inference:\n    internal: true", $compose);
        $this->assertStringNotContainsString('${OLLAMA_HOST_PORT', $compose);
    }

    public function test_model_download_is_an_explicit_profile_separate_from_runtime(): void
    {
        $compose = $this->readProjectFile('docker-compose.plesk-memory.yml');

        $this->assertStringContainsString('ollama-model-bootstrap:', $compose);
        $this->assertStringContainsString('- model-bootstrap', $compose);
        $this->assertStringContainsString("entrypoint:\n      - /bin/sh\n      - -ec\n      - |", $compose);
        $this->assertStringContainsString('ollama pull "$${LUCZOR_OLLAMA_LLM_MODEL}"', $compose);
        $this->assertStringContainsString('- ollama_models:/root/.ollama', $compose);
        $this->assertStringContainsString('ollama show "$${LUCZOR_OLLAMA_LLM_MODEL}"', $compose);
    }

    public function test_cognee_image_contains_an_offline_fastembed_model(): void
    {
        $dockerfile = $this->readProjectFile('services/cognee/Dockerfile');

        $this->assertStringContainsString('"fastembed==0.8.0"', $dockerfile);
        $this->assertStringContainsString('"onnxruntime==1.23.2"', $dockerfile);
        $this->assertStringContainsString('RUN /app/.venv/bin/python -c', $dockerfile);
        $this->assertStringContainsString("TextEmbedding(model_name='sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2'", $dockerfile);
        $this->assertStringContainsString('HF_HUB_OFFLINE=1', $dockerfile);
    }

    public function test_entrypoint_does_not_require_provider_secrets_for_local_engines(): void
    {
        $entrypoint = $this->readProjectFile('services/cognee/entrypoint.sh');

        $this->assertStringContainsString('LLM_API_KEY="${LLM_API_KEY:-$llm_provider}"', $entrypoint);
        $this->assertStringContainsString('unset EMBEDDING_API_KEY', $entrypoint);
        $this->assertStringContainsString('read_required_secret cognee_llm_api_key', $entrypoint);
        $this->assertStringContainsString('read_required_secret cognee_embedding_api_key', $entrypoint);
        $this->assertStringContainsString('ending in /v1', $entrypoint);
        $this->assertStringNotContainsString('sk-', $entrypoint);
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);

        $this->assertIsString($contents);

        return $contents;
    }
}
