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

    public function test_only_currently_executable_types_are_allowed_in_definitions(): void
    {
        // The seven executable step types stay allowed (no regression).
        foreach (['context', 'llm', 'evaluator', 'review', 'device_job', 'approval', 'manual'] as $type) {
            $this->assertTrue(WorkflowTaskCatalog::isAllowedInDefinition($type), $type);
        }
        // Newly catalogued tasks are visible but not yet allowed in definitions (P15).
        $this->assertFalse(WorkflowTaskCatalog::isAllowedInDefinition('browser.open'));
        $this->assertFalse(WorkflowTaskCatalog::isAllowedInDefinition('python.run'));
        $this->assertFalse(WorkflowTaskCatalog::isAllowedInDefinition('bogus'));
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
            'steps' => [['key' => 'bad', 'type' => 'browser.open']],
        ]);
    }
}
