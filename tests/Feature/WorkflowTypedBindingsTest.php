<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\WorkflowAuthoringService;
use App\Services\WorkflowBindings;
use App\Services\WorkflowBindingTypes;
use App\Services\WorkflowDefinitionValidator;
use App\Services\WorkflowService;
use App\Services\WorkflowStepExecutor;
use App\Services\WorkflowTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowTypedBindingsTest extends TestCase
{
    use RefreshDatabase;

    private function definition(array $extra = [], ?array $steps = null): WorkflowDefinition
    {
        return WorkflowDefinition::create(['user_id' => User::factory()->create()->id, 'name' => 'Typed inputs', 'version' => 1, 'status' => 'active',
            'definition' => array_merge(['schema_version' => 2, 'steps' => $steps ?? [['key' => 'work', 'type' => 'manual']]], $extra)]);
    }

    private function rejects(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected a schema rejection.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }

    public function test_run_input_is_checked_before_any_run_or_step_is_created(): void
    {
        $definition = $this->definition(['input_schema' => ['type' => 'object', 'properties' => ['count' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['count'], 'additionalProperties' => false]]);
        foreach ([[], ['count' => '2'], ['count' => 0], ['count' => 2, 'unknown' => true]] as $input) {
            $this->rejects(fn () => app(WorkflowService::class)->createRun($definition, $input), 'workflow_schema_');
        }
        $this->assertDatabaseCount('workflow_runs', 0);
        $this->assertDatabaseCount('workflow_steps', 0);
        $run = app(WorkflowService::class)->createRun($definition, ['count' => 2]);
        $this->assertSame(['count' => 2], $run->input);
    }

    public function test_legacy_without_input_schema_remains_compatible_and_defaults_do_not_fill_inputs(): void
    {
        $definition = $this->definition();
        $this->assertSame(['anything' => [false]], app(WorkflowService::class)->createRun($definition, ['anything' => [false]])->input);
        $definition->update(['definition' => $definition->definition + ['input_schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'default' => 'sample']], 'required' => ['name']]]]);
        $this->rejects(fn () => app(WorkflowService::class)->createRun($definition), 'workflow_schema_required');
    }

    public function test_input_uses_the_frozen_child_snapshot_instead_of_a_new_definition_schema(): void
    {
        $definition = $this->definition(['input_schema' => ['type' => 'object', 'properties' => ['value' => ['type' => 'integer']], 'required' => ['value']]]);
        $snapshot = app(WorkflowAuthoringService::class)->snapshot($definition, $definition->user_id, null);
        $updated = $definition->definition;
        $updated['input_schema']['properties']['value']['type'] = 'string';
        $definition->update(['definition' => $updated]);
        $run = app(WorkflowService::class)->createRun($definition, ['value' => 3], null, false, ['_snapshot' => $snapshot]);
        $this->assertSame(3, $run->input['value']);
        $this->rejects(fn () => app(WorkflowService::class)->createRun($definition, ['value' => 'new'], null, false, ['_snapshot' => $snapshot]), 'workflow_schema_type');
    }

    public function test_known_incompatible_input_references_are_rejected_at_definition_validation(): void
    {
        $schema = ['type' => 'object', 'properties' => ['value' => ['type' => 'integer']], 'required' => ['value']];
        foreach ([['instruction' => ['$ref' => 'input.value']], ['input_bindings' => ['instruction' => 'input.value']]] as $payload) {
            $definition = ['schema_version' => 2, 'input_schema' => $schema, 'steps' => [['key' => 'answer', 'type' => 'llm.text', 'payload' => $payload]]];
            $this->rejects(fn () => app(WorkflowDefinitionValidator::class)->validate($definition), 'workflow_binding_type_mismatch');
        }
    }

    public function test_known_array_item_and_closed_path_mismatches_are_rejected(): void
    {
        $definition = ['schema_version' => 2, 'input_schema' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'integer']]], 'additionalProperties' => false],
            'steps' => [['key' => 'answer', 'type' => 'llm.classify', 'payload' => ['instruction' => 'Classify', 'labels' => ['$ref' => 'input.items']]]]];
        $this->rejects(fn () => app(WorkflowDefinitionValidator::class)->validate($definition), 'workflow_binding_type_mismatch');
        $definition['steps'][0]['payload']['labels'] = ['$ref' => 'input.missing'];
        $this->rejects(fn () => app(WorkflowDefinitionValidator::class)->validate($definition), 'workflow_binding_schema_path_invalid');
    }

    public function test_dotted_step_keys_use_the_longest_match_for_static_and_runtime_binding(): void
    {
        $definition = $this->definition([], [
            ['key' => 'source', 'type' => 'manual'],
            ['key' => 'source.deep', 'type' => 'manual', 'payload' => ['output_schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]],
            ['key' => 'answer', 'type' => 'llm.text', 'depends_on' => ['source.deep'], 'payload' => ['instruction' => ['$ref' => 'steps.source.deep.data.name']]],
        ]);
        $steps = app(WorkflowDefinitionValidator::class)->validate($definition->definition);
        $proof = app(WorkflowBindingTypes::class)->inspect($definition->definition, $steps);
        $this->assertSame('types_compatible', $proof[0]['status']);
        $run = app(WorkflowService::class)->createRun($definition);
        $run->steps()->where('step_key', 'source')->update(['status' => 'completed', 'output' => ['deep' => ['data' => ['name' => 99]]]]);
        $run->steps()->where('step_key', 'source.deep')->update(['status' => 'completed', 'output' => ['data' => ['name' => 'Correct']]]);
        $payload = app(WorkflowBindings::class)->resolve($run->steps()->where('step_key', 'answer')->first());
        $this->assertSame('Correct', $payload['instruction']);
    }

    public function test_unknown_event_binding_is_reported_and_still_rejected_before_execution_when_invalid(): void
    {
        Queue::fake();
        $definition = $this->definition([], [['key' => 'answer', 'type' => 'llm.text', 'max_attempts' => 1, 'payload' => ['instruction' => ['$ref' => 'event.value']]]]);
        $steps = app(WorkflowDefinitionValidator::class)->validate($definition->definition);
        $proof = app(WorkflowBindingTypes::class)->inspect($definition->definition, $steps);
        $this->assertSame('runtime_required', $proof[0]['status']);
        $this->assertTrue($proof[0]['runtime_validation_required']);
        $run = app(WorkflowService::class)->advance(app(WorkflowService::class)->createRun($definition, [], null, false, ['event' => ['value' => 99]]));
        app(WorkflowStepExecutor::class)->execute($run->steps()->first()->id);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertDatabaseCount('device_jobs', 0);
    }

    public function test_definition_test_does_not_pass_with_invalid_fixture_input(): void
    {
        $definition = $this->definition(['input_schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]]);
        $tests = app(WorkflowTestService::class);
        $case = $tests->createCase($definition, ['name' => 'Missing input', 'specification' => ['input' => [], 'fixtures' => [], 'assertions' => [['step_key' => 'work', 'kind' => 'value', 'path' => 'ok', 'operator' => 'eq', 'value' => true]]]]);
        $this->rejects(fn () => $tests->start($definition, $case, 'definition', null), 'workflow_schema_required');
        $this->assertDatabaseCount('workflow_test_evidence', 0);
    }

    public function test_malformed_schema_bounds_and_null_schemas_fail_at_authoring(): void
    {
        foreach ([null, ['type' => 'array'], ['type' => 'object', 'properties' => null], ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'maxLength' => []]]], ['type' => 'object', 'additionalProperties' => 'false']] as $schema) {
            $this->rejects(fn () => app(WorkflowDefinitionValidator::class)->validate(['schema_version' => 2, 'input_schema' => $schema, 'steps' => [['key' => 'one', 'type' => 'manual']]]), $schema === null || ($schema['type'] ?? null) !== 'object' ? 'Workflow input schema' : 'workflow_schema_');
        }
    }
}
