<?php

namespace Tests\Unit;

use App\Services\WorkflowDefinitionValidator;
use App\Services\WorkflowScriptEnvironment;
use App\Services\WorkflowTaskCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowScriptEnvironmentTest extends TestCase
{
    public function test_explicit_project_environment_is_optional_and_exactly_versioned(): void
    {
        $base = ['version' => 1, 'runtime_version' => '22.22.0', 'dependencies' => []];
        WorkflowScriptEnvironment::validate('node.run', $base);
        WorkflowScriptEnvironment::validate('node.run', $base + ['lock_path' => 'locks/package-lock.json', 'lock_sha256' => str_repeat('a', 64)]);
        WorkflowScriptEnvironment::validate('python.run', ['version' => 1, 'runtime_version' => '3.12.1', 'dependencies' => [['name' => 'Sample_Package', 'version' => '1.2.3']], 'lock_path' => 'requirements.lock', 'lock_sha256' => str_repeat('b', 64)]);
        $steps = app(WorkflowDefinitionValidator::class)->validate(['schema_version' => 2, 'steps' => [['key' => 'run', 'type' => 'node.run', 'payload' => ['code' => 'console.log(1)', 'environment' => $base]]]]);
        $this->assertSame($base, $steps[0]['payload']['environment']);
        $this->assertFalse(in_array('environment', WorkflowTaskCatalog::task('node.run')['input_schema']['required'], true));
    }

    #[DataProvider('invalidEnvironments')]
    public function test_unpinned_or_dynamic_install_declarations_are_rejected(string $task, array $environment): void
    {
        $this->expectException(HttpException::class);
        WorkflowScriptEnvironment::validate($task, $environment);
    }

    public static function invalidEnvironments(): iterable
    {
        $base = ['version' => 1, 'runtime_version' => '22.22.0', 'dependencies' => []];
        yield 'unknown version' => ['node.run', array_replace($base, ['version' => 2])];
        yield 'runtime range' => ['node.run', array_replace($base, ['runtime_version' => '^22.22.0'])];
        yield 'missing lock' => ['node.run', array_replace($base, ['dependencies' => [['name' => 'safe', 'version' => '1.2.3']]])];
        yield 'range' => ['node.run', array_replace($base, ['dependencies' => [['name' => 'safe', 'version' => '^1.2.3']]])];
        yield 'url' => ['node.run', array_replace($base, ['dependencies' => [['name' => 'https://example.invalid/a', 'version' => '1.2.3']]])];
        yield 'extra install setting' => ['node.run', $base + ['global' => true]];
        yield 'reference' => ['node.run', array_replace($base, ['runtime_version' => ['$ref' => 'input.version']])];
        yield 'dependency reference' => ['node.run', array_replace($base, ['dependencies' => [['name' => 'safe', 'version' => ['$ref' => 'input.version']]]])];
        yield 'duplicate node' => ['node.run', array_replace($base, ['dependencies' => [['name' => 'safe', 'version' => '1.2.3'], ['name' => 'safe', 'version' => '2.0.0']]])];
        yield 'normalized duplicate python' => ['python.run', array_replace($base, ['dependencies' => [['name' => 'Some_Package', 'version' => '1.2.3'], ['name' => 'some-package', 'version' => '2.0.0']]])];
        foreach (['../lock', '/lock', 'C:\\lock', 'locks//lock', 'locks/./lock'] as $path) {
            yield $path => ['node.run', $base + ['lock_path' => $path, 'lock_sha256' => str_repeat('a', 64)]];
        }
        yield 'invalid hash' => ['node.run', $base + ['lock_path' => 'lock', 'lock_sha256' => str_repeat('A', 64)]];
    }

    public function test_whole_and_nested_environment_bindings_cannot_change_install_scope(): void
    {
        foreach (['environment', 'environment.runtime_version', 'environment.dependencies.0.version', 'environment.lock_sha256'] as $target) {
            try {
                app(WorkflowDefinitionValidator::class)->validate(['schema_version' => 2, 'steps' => [['key' => 'run', 'type' => 'node.run', 'payload' => ['code' => 'console.log(1)', 'input_bindings' => [$target => 'input.value']]]]]);
                $this->fail('Environment binding should not be accepted.');
            } catch (HttpException $error) {
                $this->assertSame('Bindings cannot change the approved script environment.', $error->getMessage());
            }
        }
    }
}
