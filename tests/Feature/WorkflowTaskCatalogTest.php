<?php

namespace Tests\Feature;

use App\Services\WorkflowDefinitionValidator;
use App\Services\WorkflowService;
use App\Services\WorkflowTaskCatalog;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowTaskCatalogTest extends TestCase
{
    public function test_catalog_exposes_server_and_client_tasks(): void
    {
        $this->assertNotNull(WorkflowTaskCatalog::task('context'));
        $this->assertNotNull(WorkflowTaskCatalog::task('browser.open'));
        $this->assertNull(WorkflowTaskCatalog::task('shell.rm_rf'));

        $keys = array_column(WorkflowTaskCatalog::options(), 'key');
        $this->assertContains('context', $keys);
        $this->assertContains('agent.dispatch', $keys);
    }

    public function test_every_catalogued_type_is_allowed_and_uncatalogued_are_not(): void
    {
        // The seven original step types stay allowed (no regression).
        foreach (['context', 'llm', 'evaluator', 'review', 'device_job', 'approval', 'manual'] as $type) {
            $this->assertTrue(WorkflowTaskCatalog::isAllowedInDefinition($type), $type);
        }
        // P15b — since every catalogued task has an executor path (server branch
        // or device_job bundle), the whole library is definition-ready.
        $this->assertTrue(WorkflowTaskCatalog::isAllowedInDefinition('browser.open'));
        $this->assertTrue(WorkflowTaskCatalog::isAllowedInDefinition('python.run'));
        $this->assertTrue(WorkflowTaskCatalog::isAllowedInDefinition('wait.seconds'));
        $this->assertFalse(WorkflowTaskCatalog::isAllowedInDefinition('bogus'));
    }

    public function test_auto_dispatch_marks_executor_types_but_not_external_ones(): void
    {
        foreach (['context', 'review', 'evaluator', 'workflow', 'wait.seconds', 'memory.remember', 'browser.open_url'] as $type) {
            $this->assertTrue(WorkflowTaskCatalog::isAutoDispatch($type), $type);
        }
        // Externally completed types are never handed to the executor unattended.
        foreach (['manual', 'approval', 'device_job'] as $type) {
            $this->assertFalse(WorkflowTaskCatalog::isAutoDispatch($type), $type);
        }
        $this->assertTrue(WorkflowTaskCatalog::isClientTask('browser.open'));
        $this->assertTrue(WorkflowTaskCatalog::isAutoDispatch('llm'));
        $this->assertTrue(WorkflowTaskCatalog::isClientTask('llm'));
        $this->assertFalse(WorkflowTaskCatalog::isClientTask('device_job'));
        $this->assertFalse(WorkflowTaskCatalog::isClientTask('wait.seconds'));
    }

    public function test_assert_definition_accepts_vetted_types_and_rejects_others(): void
    {
        $service = new WorkflowService;

        $steps = $service->assertDefinition([
            'steps' => [
                ['key' => 'ctx', 'type' => 'context'],
                ['key' => 'ans', 'type' => 'llm', 'depends_on' => ['ctx']],
            ],
        ]);
        $this->assertCount(2, $steps);

        $this->expectException(HttpException::class);
        $service->assertDefinition([
            'steps' => [['key' => 'bad', 'type' => 'shell.exec']],
        ]);
    }

    public function test_editor_catalog_exposes_executable_ai_file_and_script_parameters(): void
    {
        $catalog = collect(WorkflowTaskCatalog::options())->keyBy('key');
        $this->assertSame('KI-Schritt', $catalog['llm']['label']);
        $this->assertTrue($catalog['llm']['params']['instruction']['required']);
        $this->assertSame('object', $catalog['llm']['params']['output_schema']['type']);
        foreach (['python.run', 'node.run'] as $type) {
            $this->assertSame(['type' => 'textarea', 'required' => true], $catalog[$type]['params']['code']);
        }
        foreach (['file.read', 'file.write'] as $type) {
            $this->assertTrue($catalog[$type]['params']['path']['required']);
            $this->assertSame(['legacy', 'workspace'], $catalog[$type]['params']['file_scope']['enum']);
            $this->assertSame('string', $catalog[$type]['params']['workspace_root_id']['type']);
        }
        $this->assertTrue($catalog['file.write']['params']['content']['required']);
    }

    public function test_tool_center_metadata_is_present_without_changing_execution_contracts(): void
    {
        $catalog = collect(WorkflowTaskCatalog::options())->keyBy('key');

        $this->assertSame('browser', $catalog['browser.open']['capability_group']);
        $this->assertSame('browser', $catalog['browser.open']['session_kind']);
        $this->assertSame('ephemeral', $catalog['browser.open']['result_handling']);
        $this->assertSame('session', $catalog['browser.open']['approval_mode']);

        $this->assertSame('vision', $catalog['image.vision']['capability_group']);
        $this->assertSame('model', $catalog['llm.text']['session_kind']);
        $this->assertSame('terminal', $catalog['node.run']['capability_group']);
        $this->assertSame('syncable', $catalog['context']['result_handling']);
        $this->assertContains('approval_open', $catalog['browser.open']['ui_statuses']);
    }

    public function test_catalog_enumerations_match_definition_validation_without_bypassing_root_requirements(): void
    {
        $validator = new WorkflowDefinitionValidator;
        foreach (['llm' => ['inference', 'output_format'], 'condition' => ['operator'], 'file.read' => ['file_scope']] as $type => $fields) {
            foreach ($fields as $field) {
                foreach (WorkflowTaskCatalog::task($type)['params'][$field]['enum'] as $value) {
                    $validator->validatePayload($type, [$field => $value, 'workspace_root_id' => 'C:\\workspace']);
                    $this->addToAssertionCount(1);
                }
                try {
                    $validator->validatePayload($type, [$field => 'unknown-value', 'workspace_root_id' => 'C:\\workspace']);
                    $this->fail('Invalid catalog enumeration must remain rejected.');
                } catch (HttpException $error) {
                    $this->assertSame(422, $error->getStatusCode());
                }
            }
        }
        $validator->validatePayload('llm', ['instruction' => 'Summarize', 'output_format' => 'json', 'output_schema' => ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary']]]);
        $this->expectException(HttpException::class);
        $validator->validatePayload('file.write', ['path' => 'notes.txt', 'content' => '', 'file_scope' => 'workspace']);
    }
}
