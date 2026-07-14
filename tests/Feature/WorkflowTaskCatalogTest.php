<?php

namespace Tests\Feature;

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
        foreach (['llm', 'manual', 'approval', 'device_job'] as $type) {
            $this->assertFalse(WorkflowTaskCatalog::isAutoDispatch($type), $type);
        }
        $this->assertTrue(WorkflowTaskCatalog::isClientTask('browser.open'));
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
}
