<?php

namespace Tests\Unit;

use App\Services\WorkflowSchema;
use App\Services\WorkflowTaskContracts;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowTaskOutputContractsTest extends TestCase
{
    #[DataProvider('publicRunnerResults')]
    public function test_public_runner_results_keep_their_existing_shape(string $task, array $result): void
    {
        $contract = WorkflowTaskContracts::describe($task, ['runner' => 'client']);

        $this->assertSame(1, $contract['version']);
        $this->assertTrue($contract['output_schema']['additionalProperties']);
        $this->assertArrayNotHasKey('required', $contract['output_schema']);
        WorkflowSchema::validate($result, $contract['output_schema']);
        WorkflowSchema::validate([], $contract['output_schema']);
    }

    public static function publicRunnerResults(): array
    {
        // Fixtures mirror public TS results, not the underlying private native bridge payload.
        // browser.ts lifts read text; screenshots/downloads keep the nested artifact.
        $artifact = ['artifactId' => 'artifact', 'mime' => 'image/png', 'bytes' => 68,
            'sha256' => str_repeat('a', 64), 'name' => 'capture.png', 'width' => 1, 'height' => 1];

        return [
            'legacy browser read' => ['browser.read', ['ok' => true, 'text' => 'A page', 'truncated' => false]],
            'session browser read' => ['browser.read', ['ok' => true, 'sessionId' => 'session', 'tabId' => 'tab', 'url' => 'https://example.test', 'text' => 'A page', 'truncated' => false]],
            'session browser fill' => ['browser.fill', ['ok' => true, 'sessionId' => 'session', 'tabId' => 'tab', 'url' => 'https://example.test', 'data' => ['ok' => true, 'applied' => true]]],
            'legacy browser click' => ['browser.click', ['ok' => true, 'clicked' => '#button']],
            'legacy browser open' => ['browser.open_url', ['ok' => true, 'opened' => 'https://example.test']],
            'session browser screenshot' => ['browser.screenshot', ['ok' => true, 'sessionId' => 'session', 'tabId' => 'tab', 'url' => 'https://example.test', 'data' => $artifact]],
            'download without image dimensions' => ['browser.download', ['ok' => true, 'data' => array_replace($artifact, ['mime' => 'text/plain', 'width' => null, 'height' => null])]],
            'capture is the artifact' => ['image.capture', $artifact],
            'ocr' => ['image.ocr', ['ok' => true, 'text' => 'Text', 'truncated' => false, 'language' => 'de-DE', 'method' => 'windows-ocr']],
            'compare unequal dimensions omits counts' => ['image.compare', ['ok' => true, 'identical' => false, 'sameDimensions' => false]],
            'compare with counts' => ['image.compare', ['ok' => true, 'identical' => false, 'sameDimensions' => true, 'changedPixels' => 1, 'totalPixels' => 2, 'differentFraction' => 0.5, 'method' => 'exact-rgba-pixel-comparison']],
            'vision text' => ['image.vision', ['ok' => true, 'outcome' => 'success', 'text' => 'A diagram', 'model' => 'vision-model', 'provider' => 'openai', 'usage' => ['input_tokens' => 123, 'output_tokens' => 10], 'usage_source' => 'reported']],
            'vision json' => ['image.vision', ['ok' => true, 'data' => ['label' => 'diagram'], 'model' => 'vision-model', 'provider' => 'openai', 'usage' => []]],
            'legacy script' => ['node.run', ['ok' => true, 'code' => 0, 'stdout' => 'done', 'stderr' => '', 'timed_out' => false]],
            'script metadata and dynamic json' => ['python.run', ['ok' => true, 'code' => 0, 'stdout' => '[1,2]', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 10, 'runtime' => 'python', 'runtime_version' => null, 'data' => [1, 2], 'execution_environment' => 'windows_user']],
            'script json can be scalar' => ['node.run', ['ok' => true, 'code' => 0, 'stdout' => '42', 'stderr' => '', 'data' => 42]],
            'script verified package content' => ['node.run', ['ok' => true, 'code' => 0, 'environment' => ['revision' => str_repeat('b', 64), 'lock_sha256' => str_repeat('c', 64), 'dependency_count' => 2, 'reused' => true, 'installed_sha256' => str_repeat('d', 64)]]],
            'script without dependencies' => ['python.run', ['ok' => true, 'code' => 0, 'environment' => ['revision' => str_repeat('b', 64), 'lock_sha256' => null, 'dependency_count' => 0, 'reused' => false]]],
            'managed agent unconfirmed model' => ['agent.single', ['ok' => true, 'outcome' => 'success', 'text' => 'Done', 'agent' => 'codex', 'requested_model' => 'model', 'model' => null, 'duration_ms' => 10]],
            'local team with reported model' => ['agent.team', ['ok' => true, 'outcome' => 'success', 'text' => 'Done', 'agent' => 'local_orchestrated_team', 'model' => 'local-model', 'request_id' => null, 'interruption_code' => null, 'data' => ['answer' => true]]],
            'legacy agent dispatch' => ['agent.dispatch', ['ok' => true, 'code' => 0, 'stdout' => 'Done', 'stderr' => '']],
        ];
    }

    public function test_browser_and_capture_bindings_expose_only_the_actual_output_location(): void
    {
        $read = $this->schema('browser.read')['properties'];
        $capture = $this->schema('image.capture')['properties'];
        $screenshot = $this->schema('browser.screenshot')['properties'];

        $this->assertSame('string', $read['text']['type']);
        $this->assertArrayNotHasKey('data', $read);
        $this->assertSame('string', $capture['artifactId']['type']);
        $this->assertArrayNotHasKey('artifact', $capture);
        $this->assertArrayNotHasKey('artifact_id', $capture);
        $this->assertSame('string', $screenshot['data']['properties']['artifactId']['type']);
        $this->assertArrayNotHasKey('artifactId', $screenshot);
    }

    public function test_nullable_and_dynamic_fields_remain_explicitly_runtime_checked(): void
    {
        foreach ([['agent.single', 'model'], ['node.run', 'runtime_version'], ['python.run', 'data'], ['image.capture', 'width']] as [$task, $field]) {
            $schema = $this->schema($task)['properties'][$field];
            $this->assertArrayNotHasKey('type', $schema);
            $this->assertStringContainsString('runtime', $schema['description']);
        }
    }

    #[DataProvider('invalidKnownFieldTypes')]
    public function test_known_output_field_types_are_checked(string $task, array $result): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('workflow_schema_type:');
        WorkflowSchema::validate($result, $this->schema($task));
    }

    public static function invalidKnownFieldTypes(): array
    {
        return [
            ['browser.read', ['text' => ['unexpected']]],
            ['browser.fill', ['data' => ['applied' => 'yes']]],
            ['browser.screenshot', ['data' => ['bytes' => '68']]],
            ['image.capture', ['artifactId' => 1]],
            ['image.ocr', ['truncated' => 'false']],
            ['image.compare', ['differentFraction' => '0.5']],
            ['image.vision', ['usage' => ['input_tokens' => '123']]],
            ['node.run', ['code' => '0']],
            ['node.run', ['environment' => ['installed_sha256' => 123]]],
            ['agent.single', ['text' => []]],
            ['agent.dispatch', ['stdout' => []]],
        ];
    }

    public function test_existing_llm_and_server_requirements_are_preserved(): void
    {
        $this->assertSame(['ok', 'text'], $this->schema('llm.text')['required']);
        $this->assertSame(['ok', 'data'], $this->schema('llm.json')['required']);
        $this->assertSame(['data'], $this->schema('llm.classify')['required']);
        $this->assertSame(['outcome', 'data'], $this->schema('data.map')['required']);
        $this->assertSame(['outcome', 'data'], $this->schema('control.foreach')['required']);
    }

    public function test_installed_package_hash_keeps_its_exact_length(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('workflow_schema_string_size:$.environment.installed_sha256');
        WorkflowSchema::validate(['environment' => ['installed_sha256' => str_repeat('a', 63)]], $this->schema('python.run'));
    }

    private function schema(string $task): array
    {
        return WorkflowTaskContracts::describe($task, ['runner' => 'client'])['output_schema'];
    }
}
