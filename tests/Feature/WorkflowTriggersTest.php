<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\GithubWebhookDelivery;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Models\WorkflowTrigger;
use App\Models\WorkflowTriggerDelivery;
use App\Services\AutomationGrantService;
use App\Services\WorkflowEventService;
use App\Services\WorkflowTriggerDispatcher;
use App\Services\WorkflowTriggerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowTriggersTest extends TestCase
{
    use RefreshDatabase;

    private function definition(?User $user = null, ?Project $project = null, array $steps = []): WorkflowDefinition
    {
        $user ??= User::factory()->create();

        return WorkflowDefinition::create(['user_id' => $user->id, 'project_id' => $project?->id, 'name' => 'Dynamic workflow', 'version' => 1, 'status' => 'active',
            'definition' => ['steps' => $steps ?: [['key' => 'manual', 'type' => 'manual']]]]);
    }

    private function trigger(WorkflowDefinition $definition, string $kind, array $config = []): WorkflowTrigger
    {
        $result = app(WorkflowTriggerService::class)->save($definition, ['name' => 'Subscription', 'kind' => $kind, 'config' => $config, 'enabled' => true]);

        return WorkflowTrigger::findOrFail($result['id']);
    }

    private function token(User $user, string $deviceId = 'device-a'): string
    {
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Workflow test', 'device_id' => $deviceId, 'abilities' => ['brain.read', 'brain.write', 'device.connect'], 'active' => true]);

        return $minted['plain'];
    }

    public function test_schedule_coalesces_missed_ticks_and_once_runs_once(): void
    {
        $this->travelTo(Carbon::parse('2026-09-08T08:00:00Z'));
        $definition = $this->definition();
        $trigger = $this->trigger($definition, 'schedule', ['cron' => '*/5 * * * *', 'timezone' => 'Europe/Berlin']);
        $once = $this->trigger($definition, 'schedule', ['run_at' => '2026-09-08T10:01:00+02:00', 'timezone' => 'Europe/Berlin']);
        $this->travelTo(Carbon::parse('2026-09-08T08:21:00Z'));
        $this->assertSame(2, app(WorkflowTriggerService::class)->enqueueDue());
        $this->assertSame(0, app(WorkflowTriggerService::class)->enqueueDue());
        $this->assertNull($once->fresh()->next_due_at);
        $event = WorkflowEvent::where('source', 'schedule:'.$trigger->id)->sole();
        $this->assertTrue($event->payload['coalesced']);
        $this->assertSame('2026-09-08T08:20:00.000000Z', $event->payload['scheduled_at']);
        $this->assertSame('2026-09-08 08:25:00', $trigger->fresh()->next_due_at->toDateTimeString());
    }

    public function test_dst_repeated_wall_minute_does_not_create_two_runs(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25T00:29:00Z'));
        $trigger = $this->trigger($this->definition(), 'schedule', ['cron' => '30 2 * * *', 'timezone' => 'Europe/Berlin']);
        $this->travelTo(Carbon::parse('2026-10-25T00:31:00Z'));
        app(WorkflowTriggerService::class)->enqueueDue();
        $trigger->update(['next_due_at' => Carbon::parse('2026-10-25T01:30:00Z')]);
        $this->travelTo(Carbon::parse('2026-10-25T01:31:00Z'));
        app(WorkflowTriggerService::class)->enqueueDue();
        $this->assertSame(1, WorkflowEvent::where('source', 'schedule:'.$trigger->id)->count());
    }

    public function test_watcher_globs_agree_for_root_nested_and_excluded_paths(): void
    {
        $service = app(WorkflowTriggerService::class);
        $this->assertTrue($service->globMatches('**/*.ts', 'main.ts'));
        $this->assertTrue($service->globMatches('**/*.ts', 'src/nested/main.ts'));
        $this->assertFalse($service->globMatches('*.ts', 'src/main.ts'));
        $this->assertTrue($service->globMatches('.git/**', '.git/config'));
        $this->assertTrue($service->globMatches('src/?.ts', 'SRC/a.TS', true));
    }

    public function test_events_dedupe_and_preserve_distinct_payloads_with_causal_loop_suppression(): void
    {
        $definition = $this->definition();
        $trigger = $this->trigger($definition, 'task.completed');
        $service = app(WorkflowEventService::class);
        $first = $service->record($definition->user_id, null, 'task.completed', 'test', 'a', ['task_id' => 1]);
        $again = $service->record($definition->user_id, null, 'task.completed', 'test', 'a', ['task_id' => 1]);
        $service->record($definition->user_id, null, 'task.completed', 'test', 'b', ['task_id' => 2]);
        $service->record($definition->user_id, null, 'task.completed', 'test', 'c', ['task_id' => 3], ['causal_trigger_ids' => [$trigger->id]]);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(3, $service->publishPending());
        $this->assertSame(0, $service->publishPending());
        $this->assertSame(['pending', 'pending', 'suppressed_loop'], WorkflowTriggerDelivery::orderBy('id')->pluck('status')->all());
    }

    public function test_dispatch_serializes_runs_and_pins_revision_when_admitted(): void
    {
        Queue::fake();
        $definition = $this->definition();
        $this->trigger($definition, 'task.completed');
        $events = app(WorkflowEventService::class);
        foreach (['one', 'two'] as $key) {
            $events->record($definition->user_id, null, 'task.completed', 'test', $key, ['task_id' => 1]);
        }
        $events->publishPending();
        $dispatcher = app(WorkflowTriggerDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchPending());
        $this->assertSame(0, $dispatcher->dispatchPending());
        $firstRun = WorkflowRun::sole();
        $firstRun->update(['status' => 'completed', 'finished_at' => now()]);
        $definition->update(['version' => 2, 'definition' => ['steps' => [['key' => 'updated', 'type' => 'manual']]]]);
        $this->assertSame(1, $dispatcher->dispatchPending());
        $this->assertSame('updated', WorkflowRun::latest('id')->first()->steps()->sole()->step_key);
        $this->assertSame('manual', $firstRun->steps()->sole()->step_key);
    }

    public function test_file_bursts_coalesce_paths_but_keep_inbox_evidence(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'external_id' => 'p', 'name' => 'P', 'status' => 'active']);
        Device::create(['user_id' => $user->id, 'device_id' => 'device-a', 'name' => 'A', 'status' => 'online']);
        $trigger = $this->trigger($this->definition($user, $project), 'workspace.file_changed', ['device_id' => 'device-a', 'root_path' => 'E:/repo', 'paths' => ['src/*']]);
        $events = app(WorkflowEventService::class);
        foreach (['src/a.ts', 'src/b.ts'] as $index => $path) {
            $events->record($user->id, $project->id, 'workspace.file_changed', 'device:a', (string) $index, ['_trigger_id' => $trigger->id, 'changes' => [['path' => $path, 'kind' => 'modified']]]);
        }
        $events->publishPending();
        $this->assertDatabaseCount('workflow_events', 2);
        $this->assertSame(['coalesced', 'pending'], WorkflowTriggerDelivery::orderBy('id')->pluck('status')->all());
        $this->assertCount(2, WorkflowTriggerDelivery::latest('id')->first()->event_payload['changes']);
    }

    public function test_disabled_delivery_head_does_not_starve_an_enabled_trigger_with_a_small_limit(): void
    {
        Queue::fake();
        $pausedDefinition = $this->definition();
        $paused = $this->trigger($pausedDefinition, 'task.completed');
        $activeDefinition = $this->definition();
        $active = $this->trigger($activeDefinition, 'task.completed');
        $events = app(WorkflowEventService::class);
        foreach (['old-one', 'old-two'] as $key) {
            $events->record($pausedDefinition->user_id, null, 'task.completed', 'test', $key, ['task_id' => 1]);
        }
        $events->publishPending();
        $paused->update(['enabled' => false]);
        $events->record($activeDefinition->user_id, null, 'task.completed', 'test', 'active-later', ['task_id' => 2]);
        $events->publishPending();
        $this->assertSame(1, app(WorkflowTriggerDispatcher::class)->dispatchPending(2));
        $this->assertSame(['pending', 'pending'], WorkflowTriggerDelivery::where('workflow_trigger_id', $paused->id)->orderBy('id')->pluck('status')->all());
        $this->assertSame('running', WorkflowTriggerDelivery::where('workflow_trigger_id', $active->id)->sole()->status);
        $this->assertSame($activeDefinition->id, WorkflowRun::sole()->workflow_definition_id);
    }

    private function approvedGrant(WorkflowDefinition $definition): array
    {
        Device::firstOrCreate(['user_id' => $definition->user_id, 'device_id' => 'device-a'], ['name' => 'A', 'status' => 'online']);
        $data = ['status' => 'active', 'operation_id' => (string) Str::uuid(), 'local_approved' => true, 'approved_revision' => 1,
            'device_id' => 'device-a', 'project_external_id' => null, 'root_path' => 'E:/repo', 'allowed_tasks' => ['node.run'],
            'allowed_input_sources' => ['input', 'event', 'steps'], 'allowed_output_keys' => ['*'], 'egress_hosts' => [],
            'export_results' => true, 'script_hashes' => ['script' => hash('sha256', 'console.log(1)')]];
        $grant = app(AutomationGrantService::class)->configure($definition, $data, $definition->user_id, 'device-a');

        return [$grant, $data];
    }

    public function test_safe_revisions_reuse_grant_but_changed_script_requires_approval(): void
    {
        $definition = $this->definition();
        [$grant] = $this->approvedGrant($definition);
        $graph = ['definition' => ['steps' => [['key' => 'script', 'type' => 'node.run', 'payload' => ['code' => 'console.log(1)']]]]];
        $definition->update(['version' => 2]);
        $authorized = app(AutomationGrantService::class)->authorizeRun($definition, $graph, ['device_id' => 'device-a']);
        $this->assertSame($grant->id, $authorized['id']);
        $graph['definition']['steps'][0]['payload']['code'] = 'console.log(2)';
        $this->expectException(HttpException::class);
        app(AutomationGrantService::class)->authorizeRun($definition, $graph, ['device_id' => 'device-a']);
    }

    public function test_revoked_grant_cannot_execute_queued_device_work(): void
    {
        $definition = $this->definition();
        [$grant, $data] = $this->approvedGrant($definition);
        $run = WorkflowRun::create(['public_id' => (string) Str::uuid(), 'user_id' => $definition->user_id, 'workflow_definition_id' => $definition->id, 'status' => 'running',
            'context' => ['_execution' => ['automatic' => true, 'grant' => $grant->toArray()]]]);
        $data['status'] = 'revoked';
        $data['operation_id'] = (string) Str::uuid();
        app(AutomationGrantService::class)->configure($definition, $data, $definition->user_id, 'device-a');
        $this->expectException(HttpException::class);
        app(AutomationGrantService::class)->authorizeTask($run, 'node.run', ['code' => 'console.log(1)']);
    }

    public function test_hook_authentication_idempotency_and_payload_identity(): void
    {
        $definition = $this->definition();
        $result = app(WorkflowTriggerService::class)->save($definition, ['name' => 'Hook', 'kind' => 'webhook', 'config' => [], 'enabled' => true]);
        $url = '/api/v1/workflow-hooks/'.$result['public_id'];
        $this->postJson($url, ['value' => 1])->assertUnauthorized();
        $headers = ['X-Workflow-Secret' => $result['webhook_secret'], 'X-Workflow-Event-ID' => 'delivery-1'];
        $this->withHeaders($headers)->postJson($url, ['value' => 1])->assertAccepted()->assertJsonPath('data.status', 'accepted');
        $this->withHeaders($headers)->postJson($url, ['value' => 1])->assertAccepted()->assertJsonPath('data.status', 'duplicate');
        $this->withHeaders($headers)->postJson($url, ['value' => 2])->assertConflict();
        $this->assertDatabaseCount('workflow_events', 1);
    }

    public function test_file_events_enforce_owned_device_scope_and_metadata_only(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);
        $project = Project::create(['user_id' => $user->id, 'external_id' => 'p', 'name' => 'P', 'status' => 'active']);
        Device::create(['user_id' => $user->id, 'device_id' => 'device-a', 'name' => 'A', 'status' => 'online']);
        $trigger = $this->trigger($this->definition($user, $project), 'workspace.file_changed', ['device_id' => 'device-a', 'root_path' => 'E:/repo', 'paths' => ['src/*']]);
        $data = ['trigger_id' => $trigger->id, 'device_id' => 'device-a', 'event_id' => (string) Str::uuid(), 'root_path' => 'E:\\repo',
            'occurred_at' => now()->toISOString(), 'changes' => [['path' => 'src/a.ts', 'kind' => 'modified']]];
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflow-events', $data + ['content' => 'private'])->assertUnprocessable();
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflow-events', $data)->assertAccepted();
        $data['changes'] = [['path' => '../secret', 'kind' => 'modified']];
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflow-events', $data)->assertUnprocessable();
        $data['changes'] = [['path' => '', 'kind' => 'rescan']];
        $data['event_id'] = (string) Str::uuid();
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflow-events', $data)->assertAccepted();
    }

    public function test_task_terminal_event_is_written_once_with_status_transaction(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);
        $task = Task::create(['user_id' => $user->id, 'client_id' => 'test', 'external_id' => (string) Str::uuid(), 'title' => 'Finish', 'status' => 'open', 'priority' => 'normal']);
        $this->withHeader('X-Api-Key', $token)->patchJson('/api/v1/tasks/'.$task->external_id, ['status' => 'done'])->assertOk();
        $this->withHeader('X-Api-Key', $token)->patchJson('/api/v1/tasks/'.$task->external_id, ['status' => 'done'])->assertOk();
        $this->assertSame(1, WorkflowEvent::where('kind', 'task.completed')->count());
        $this->withHeader('X-Api-Key', $token)->patchJson('/api/v1/tasks/'.$task->external_id, ['status' => 'open'])->assertOk();
        $this->withHeader('X-Api-Key', $token)->patchJson('/api/v1/tasks/'.$task->external_id, ['status' => 'done'])->assertOk();
        $this->assertSame(2, WorkflowEvent::where('kind', 'task.completed')->count());
    }

    public function test_shared_operation_ledger_recovers_trigger_and_encrypts_hook_secret(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);
        $definition = $this->definition($user);
        $operation = (string) Str::uuid();
        $data = ['name' => 'Hook', 'kind' => 'webhook', 'config' => [], 'enabled' => true, 'operation_id' => $operation];
        $first = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflows/'.$definition->id.'/triggers', $data)->assertCreated();
        $second = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflows/'.$definition->id.'/triggers', $data)->assertCreated();
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertDatabaseCount('workflow_triggers', 1);
        $raw = DB::table('workflow_operations')->where('operation_id', $operation)->value('response');
        $this->assertStringNotContainsString($first->json('data.webhook_secret'), $raw);
        $this->withHeader('X-Api-Key', $token)->getJson('/api/v1/workflow-operations/'.$operation)->assertOk()->assertJsonPath('data.response.id', $first->json('data.id'));
        $data['name'] = 'Different';
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflows/'.$definition->id.'/triggers', $data)->assertConflict();
    }

    public function test_terminal_outbox_recovers_crash_after_status_commit(): void
    {
        $definition = $this->definition();
        $this->trigger($definition, 'workflow.completed');
        $run = WorkflowRun::create(['public_id' => (string) Str::uuid(), 'user_id' => $definition->user_id, 'workflow_definition_id' => $definition->id,
            'status' => 'completed', 'finished_at' => now()]);
        $this->assertSame(1, app(WorkflowEventService::class)->publishPending());
        $this->assertNotNull($run->fresh()->terminal_event_published_at);
        $this->assertSame(0, app(WorkflowEventService::class)->publishPending());
        $this->assertDatabaseCount('workflow_trigger_deliveries', 1);
    }

    public function test_grant_input_source_expansion_is_rejected(): void
    {
        $definition = $this->definition();
        [, $data] = $this->approvedGrant($definition);
        $data['allowed_input_sources'] = ['input'];
        $data['operation_id'] = (string) Str::uuid();
        app(AutomationGrantService::class)->configure($definition, $data, $definition->user_id, 'device-a');
        $this->expectException(HttpException::class);
        app(AutomationGrantService::class)->authorizeRun($definition, ['steps' => [['key' => 'script', 'type' => 'node.run',
            'payload' => ['code' => 'console.log(1)', 'input_bindings' => ['title' => 'event.payload.title']]]]], ['device_id' => 'device-a']);
    }

    public function test_grant_api_preserves_empty_hash_map_and_exact_network_port(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user);
        [, $data] = $this->approvedGrant($definition);
        $data['allowed_tasks'] = ['api.call'];
        $data['script_hashes'] = (object) [];
        $data['egress_hosts'] = ['example.test:8443'];
        $data['operation_id'] = (string) Str::uuid();
        $token = $this->token($user);
        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflows/'.$definition->id.'/automation', $data)->assertOk();
        $this->assertStringContainsString('"script_hashes":{}', $response->getContent());
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/workflows/'.$definition->id.'/automation', $data)->assertOk();
        $graph = ['steps' => [['key' => 'call', 'type' => 'api.call', 'payload' => ['url' => 'https://example.test:8443/path']]]];
        $this->assertNotEmpty(app(AutomationGrantService::class)->authorizeRun($definition, $graph, ['device_id' => 'device-a']));
        $graph['steps'][0]['payload']['url'] = 'https://example.test:9443/path';
        $this->expectException(HttpException::class);
        app(AutomationGrantService::class)->authorizeRun($definition, $graph, ['device_id' => 'device-a']);
    }

    public function test_source_picker_never_leaks_other_users_imports(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $definition = $this->definition($user);
        $this->definition($other);
        Repository::create(['user_id' => $other->id, 'provider' => 'github', 'full_name' => 'private/repo', 'default_branch' => 'main']);
        $this->withHeader('X-Api-Key', $this->token($user))->getJson('/api/v1/workflow-trigger-sources')
            ->assertOk()->assertJsonCount(0, 'data.repositories')->assertJsonCount(1, 'data.workflows')->assertJsonPath('data.workflows.0.id', $definition->id);
    }

    public function test_github_pending_inbox_recovers_and_fans_out_only_to_matching_imports(): void
    {
        Config::set('services.github.webhook_secret', 'hook-secret');
        $owners = [User::factory()->create(), User::factory()->create()];
        foreach ($owners as $owner) {
            Repository::create(['user_id' => $owner->id, 'provider' => 'github', 'full_name' => 'org/repo', 'default_branch' => 'main']);
        }
        $payload = ['repository' => ['full_name' => 'org/repo'], 'ref' => 'refs/heads/main', 'after' => str_repeat('a', 40)];
        $raw = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'hook-secret');
        GithubWebhookDelivery::create(['delivery_id' => 'recover-1', 'event' => 'push', 'signature' => $signature, 'payload' => $payload, 'status' => 'received']);
        $response = $this->call('POST', '/api/v1/github/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature, 'HTTP_X_GITHUB_DELIVERY' => 'recover-1', 'HTTP_X_GITHUB_EVENT' => 'push'], $raw);
        $response->assertOk();
        $this->assertSame(2, WorkflowEvent::where('kind', 'github.push')->count());
        $this->assertSame($owners[0]->id, WorkflowEvent::oldest('id')->first()->user_id);
        $this->assertSame('processed', GithubWebhookDelivery::sole()->status);
    }
}
