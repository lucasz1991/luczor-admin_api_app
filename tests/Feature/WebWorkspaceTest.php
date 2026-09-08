<?php

namespace Tests\Feature;

use App\Events\DeviceJobCreated;
use App\Livewire\Account\Workspace;
use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\User;
use App\Models\WebWorkspaceChat;
use App\Models\WebWorkspaceTurn;
use App\Services\DeviceJobSigner;
use App\Services\MemoryOrchestrator;
use App\Services\WebWorkspaceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class WebWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(DeviceJobSigner::class, fn ($mock) => $mock->shouldReceive('sign')->andReturn('test-signature'));
        $this->mock(MemoryOrchestrator::class, fn ($mock) => $mock->shouldReceive('recall')->andReturn([]));
    }

    private function device(User $user, string $name = 'My desktop'): Device
    {
        return Device::create(['user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'name' => $name, 'status' => 'online', 'last_seen_at' => now()]);
    }

    private function assertOwnedResourceIsHidden(callable $action): void
    {
        try {
            $action();
            $this->fail('A foreign resource must not be found.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_overview_scopes_devices_chats_and_actions_even_for_admins(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create();
        $mine = $this->device($user);
        $foreign = $this->device($other, 'Foreign-private-device');
        $chat = WebWorkspaceChat::create(['user_id' => $other->id, 'title' => 'Foreign-private-chat', 'scope' => 'personal']);
        Conversation::create(['user_id' => $other->id, 'client_id' => $foreign->device_id, 'external_id' => 'secret', 'title' => 'Foreign-archive-title']);
        $this->actingAs($user);
        Livewire::test(Workspace::class)->assertSee($mine->name)->assertDontSee($foreign->name)->assertDontSee($chat->title)->assertDontSee('Foreign-archive-title');
        $this->assertOwnedResourceIsHidden(fn () => Livewire::test(Workspace::class)->call('openChat', $chat->id));
        $this->assertOwnedResourceIsHidden(fn () => Livewire::test(Workspace::class)->call('selectDevice', $foreign->id));
    }

    public function test_personal_chat_dispatch_uses_only_own_memories_and_its_own_history(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $user->forceFill(['master_device_id' => $device->id])->save();
        $this->mock(MemoryOrchestrator::class, fn ($mock) => $mock->shouldReceive('recall')->once()->with('Hello', 'user', ['user_id' => $user->id, 'tenant_id' => $user->tenant_id], 4)->andReturn([['id' => 'memory-one', 'content' => 'Personal preference', 'priority' => 'high', 'meta' => ['secret' => 'Never transfer']]]));
        $this->actingAs($user);
        Livewire::test(Workspace::class)->call('newChat', 'personal')->set('prompt', 'Hello')->call('send')->assertHasNoErrors();
        $job = DeviceJob::firstOrFail();
        $this->assertSame('workspace.chat', $job->tool_profile);
        $this->assertSame('approval_required', $job->status);
        $this->assertSame('personal', $job->payload['scope']);
        $this->assertSame([], $job->payload['history']);
        $this->assertNull($job->project_id);
        $this->assertSame($user->id, $job->payload['user_id']);
        $this->assertSame($device->device_id, $job->payload['device_id']);
        $this->assertSame(['id' => 'memory-one', 'content' => 'Personal preference', 'priority' => 'high'], $job->payload['personal_memories'][0]);
        $this->assertDatabaseCount('web_workspace_turns', 1);
        $this->assertNotSame('Hello', WebWorkspaceTurn::firstOrFail()->getRawOriginal('prompt'));
        $rawPayload = DB::table('device_jobs')->value('payload');
        $this->assertStringNotContainsString('Hello', $rawPayload);
        $this->assertStringNotContainsString('Personal preference', $rawPayload);
        $this->assertStringNotContainsString('Personal preference', DB::table('audit_events')->where('event_type', 'device_job.created')->value('payload'));
        $job->update(['result' => ['text' => 'Private answer']]);
        $this->assertStringNotContainsString('Private answer', DB::table('device_jobs')->value('result'));
        $this->assertSame('Private answer', $job->fresh()->result['text']);
    }

    public function test_submission_is_idempotent_and_queue_can_be_cancelled_without_starting_a_second_job(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $service = app(WebWorkspaceService::class);
        $chat = $service->createChat($user, 'workspace');
        $submission = (string) Str::uuid();
        $first = $service->submit($user, $chat->id, $device->id, 'Summarize', $submission);
        $second = $service->submit($user, $chat->id, $device->id, 'Summarize', $submission);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('device_jobs', 1);
        $service->cancelQueued($user, $chat->id, $first->id);
        $this->assertSame('cancelled', $first->deviceJob->fresh()->status);
        $service->submit($user, $chat->id, $device->id, 'Try again', (string) Str::uuid());
        $this->assertDatabaseCount('device_jobs', 2);
    }

    public function test_pending_turn_blocks_duplicate_work_and_history_ignores_failed_answers(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $this->actingAs($user);
        $component = Livewire::test(Workspace::class)->call('newChat', 'workspace')->set('prompt', 'First')->call('send');
        $component->set('prompt', 'Second')->call('send')->assertHasErrors('prompt');
        $this->assertDatabaseCount('device_jobs', 1);
        DeviceJob::firstOrFail()->update(['status' => 'completed', 'result' => ['text' => 'First answer']]);
        $component->set('prompt', 'Second')->call('send')->assertHasNoErrors();
        $this->assertSame([['role' => 'user', 'content' => 'First'], ['role' => 'assistant', 'content' => 'First answer']], DeviceJob::latest('id')->firstOrFail()->payload['history']);
        $component->call('newChat', 'personal')->set('prompt', 'Unrelated')->call('send')->assertHasNoErrors();
        $this->assertSame([], DeviceJob::latest('id')->firstOrFail()->payload['history']);
    }

    public function test_suspended_users_and_foreign_targets_cannot_submit(): void
    {
        $user = User::factory()->create();
        $foreign = $this->device(User::factory()->create());
        $this->actingAs($user);
        $this->assertOwnedResourceIsHidden(fn () => Livewire::test(Workspace::class)->call('newChat', 'personal')->set('targetDevice', $foreign->id)->set('prompt', 'Hello')->call('send'));
        $user->update(['status' => false]);
        Livewire::test(Workspace::class)->assertForbidden();
        $this->assertDatabaseCount('device_jobs', 0);
    }

    public function test_admin_job_listing_never_decrypts_another_users_web_chat_but_retains_operations_overview(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create();
        $ownDevice = $this->device($admin);
        $foreignDevice = $this->device($other);
        $service = app(WebWorkspaceService::class);
        $ownChat = $service->createChat($admin, 'personal');
        $foreignChat = $service->createChat($other, 'personal');
        $ownTurn = $service->submit($admin, $ownChat->id, $ownDevice->id, 'Own-readable-prompt', (string) Str::uuid());
        $foreignTurn = $service->submit($other, $foreignChat->id, $foreignDevice->id, 'Foreign-private-prompt', (string) Str::uuid());
        $foreignTurn->deviceJob->update(['result' => ['text' => 'Foreign-private-answer']]);
        $token = ApiKey::mint(['user_id' => $admin->id, 'name' => 'Admin device', 'device_id' => $ownDevice->device_id, 'abilities' => ApiKey::DEVICE_ABILITIES, 'active' => true])['plain'];
        $this->withHeader('X-Api-Key', $token);
        $operationId = $this->postJson('/api/v1/device-jobs', ['device_id' => $foreignDevice->device_id, 'tool_profile' => 'desktop.windows.list'])->assertCreated()->json('data.id');
        $response = $this->getJson('/api/v1/device-jobs')->assertOk();
        $response->assertDontSee('Foreign-private-prompt')->assertDontSee('Foreign-private-answer')->assertSee('Own-readable-prompt');
        $listed = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($listed->contains($ownTurn->device_job_id));
        $this->assertTrue($listed->contains($operationId));
        $this->assertFalse($listed->contains($foreignTurn->device_job_id));
        $this->getJson('/api/v1/devices/jobs/next?client_id='.$foreignDevice->device_id)->assertForbidden();
    }

    public function test_admin_cannot_dispatch_a_foreign_web_chat_or_attach_foreign_context(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create();
        $ownDevice = $this->device($admin);
        $foreignDevice = $this->device($other);
        $service = app(WebWorkspaceService::class);
        $ownChat = $service->createChat($admin, 'personal');
        $foreignChat = $service->createChat($other, 'personal');
        $token = ApiKey::mint(['user_id' => $admin->id, 'name' => 'Admin device', 'device_id' => $ownDevice->device_id, 'abilities' => ApiKey::DEVICE_ABILITIES, 'active' => true])['plain'];
        $this->withHeader('X-Api-Key', $token);
        $payload = ['user_id' => $other->id, 'device_id' => $foreignDevice->device_id, 'chat_id' => $foreignChat->id, 'scope' => 'personal', 'prompt' => 'Forged instruction', 'history' => [], 'personal_memories' => []];
        $this->postJson('/api/v1/device-jobs', ['device_id' => $foreignDevice->device_id, 'tool_profile' => 'workspace.chat', 'payload' => $payload])->assertNotFound();
        $this->postJson('/api/v1/device-jobs', ['device_id' => $ownDevice->device_id, 'tool_profile' => 'workspace.chat', 'payload' => $payload])->assertNotFound();
        $payload['chat_id'] = $ownChat->id;
        $this->postJson('/api/v1/device-jobs', ['device_id' => $ownDevice->device_id, 'project_id' => 'foreign-project', 'tool_profile' => 'workspace.chat', 'payload' => $payload])->assertUnprocessable();
        $payload['scope'] = 'workspace';
        $this->postJson('/api/v1/device-jobs', ['device_id' => $ownDevice->device_id, 'tool_profile' => 'workspace.chat', 'payload' => $payload])->assertUnprocessable();
        $this->assertDatabaseCount('device_jobs', 0);
        $payload['scope'] = 'personal';
        $this->postJson('/api/v1/device-jobs', ['device_id' => $ownDevice->device_id, 'tool_profile' => 'workspace.chat', 'payload' => $payload])->assertCreated()->assertJsonPath('data.payload.user_id', $admin->id)->assertJsonPath('data.payload.device_id', $ownDevice->device_id);
    }

    public function test_failed_device_job_without_result_becomes_failed_and_unblocks_the_chat(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $service = app(WebWorkspaceService::class);
        $chat = $service->createChat($user, 'personal');
        $turn = $service->submit($user, $chat->id, $device->id, 'Hello', (string) Str::uuid());
        $job = $turn->deviceJob;
        $job->update(['status' => 'running', 'started_at' => now()]);
        $token = ApiKey::mint(['user_id' => $user->id, 'name' => 'Device', 'device_id' => $device->device_id, 'abilities' => ApiKey::DEVICE_ABILITIES, 'active' => true])['plain'];
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/devices/jobs/'.$job->public_id.'/complete', ['client_id' => $device->device_id, 'ok' => false, 'error' => 'Device busy'])->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.result', null);
        $service->submit($user, $chat->id, $device->id, 'Retry', (string) Str::uuid());
        $this->assertDatabaseCount('device_jobs', 2);
    }

    public function test_cancelled_jobs_cannot_be_reactivated_by_approval_or_start(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $service = app(WebWorkspaceService::class);
        $chat = $service->createChat($user, 'personal');
        $turn = $service->submit($user, $chat->id, $device->id, 'Hello', (string) Str::uuid());
        $job = $turn->deviceJob;
        $service->cancelQueued($user, $chat->id, $turn->id);
        $token = ApiKey::mint(['user_id' => $user->id, 'name' => 'Device', 'device_id' => $device->device_id, 'abilities' => ApiKey::DEVICE_ABILITIES, 'active' => true])['plain'];
        $this->withHeader('X-Api-Key', $token);
        $this->postJson('/api/v1/devices/jobs/'.$job->public_id.'/approve', ['client_id' => $device->device_id, 'approved' => true])->assertConflict();
        $this->postJson('/api/v1/devices/jobs/'.$job->public_id.'/start', ['client_id' => $device->device_id])->assertConflict();
        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertSame(0, $job->approvals()->count());
    }

    public function test_admin_device_enrollment_cannot_take_over_another_account_or_read_its_old_jobs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create();
        $device = $this->device($other);
        $service = app(WebWorkspaceService::class);
        $chat = $service->createChat($other, 'personal');
        $turn = $service->submit($other, $chat->id, $device->id, 'Private-device-history', (string) Str::uuid());
        $token = ApiKey::mint(['user_id' => $admin->id, 'name' => 'New admin device', 'abilities' => ApiKey::DEVICE_ABILITIES, 'active' => true])['plain'];
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/devices/register', ['client_id' => $device->device_id, 'name' => 'Takeover'])->assertNotFound();
        $this->assertSame($other->id, $device->fresh()->user_id);
        // Even an explicit later device reassignment must not expose the previous owner's jobs.
        $device->update(['user_id' => $admin->id]);
        $this->assertSame([], (new DeviceJobCreated($turn->deviceJob->fresh()))->broadcastOn());
        $this->getJson('/api/v1/devices/jobs/next?client_id='.$device->device_id)->assertOk()->assertJsonPath('data', null)->assertDontSee('Private-device-history');
        $this->postJson('/api/v1/devices/jobs/'.$turn->deviceJob->public_id.'/approve', ['client_id' => $device->device_id, 'approved' => true])->assertNotFound();
    }
}
