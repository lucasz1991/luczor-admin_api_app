<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\Device;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_device_messages_preserve_text_and_idempotency_share_metadata_revision_and_are_private(): void
    {
        $user = User::factory()->create();
        $first = $this->token($user, 'one');
        $second = $this->token($user, 'two');
        $conversation = Conversation::create(['user_id' => $user->id, 'external_id' => (string) Str::uuid(), 'client_id' => 'one', 'title' => 'Test']);
        $base = '/api/v1/conversations/'.$conversation->external_id;
        $body = ['expected_revision' => 0, 'messages' => [['id' => (string) Str::uuid(), 'role' => 'assistant', 'content' => "  Öffentlich ä\n", 'created_at' => 1000]]];
        $this->withHeader('X-Api-Key', $first)->postJson($base.'/messages', $body)->assertOk()->assertJsonPath('data.revision', 1);
        $this->withHeader('X-Api-Key', $second)->postJson($base.'/messages', $body)->assertOk()->assertJsonPath('data.replayed', true);
        $this->getJson($base.'/messages?after=0&limit=1')->assertOk()->assertJsonPath('data.messages.0.content', "  Öffentlich ä\n")->assertJsonPath('data.next_cursor', 1);
        $changed = $body;
        $changed['messages'][0]['content'] = 'Mutated';
        $this->postJson($base.'/messages', $changed)->assertConflict();
        $changed['messages'][0]['id'] = (string) Str::uuid();
        $this->postJson($base.'/messages', $changed)->assertConflict();
        $this->patchJson($base, ['expected_revision' => 1, 'title' => 'Renamed', 'archived' => true])->assertOk()->assertJsonPath('data.revision', 2);
        $this->getJson($base.'/messages')->assertJsonPath('data.revision', 2);
        $this->assertStringNotContainsString('Öffentlich', DB::table('luczor_message_archives')->sole()->payload);
        $this->withHeader('X-Api-Key', $this->token(User::factory()->create(['role' => 'admin']), 'foreign'))->getJson($base.'/messages')->assertNotFound();
        $this->postJson($base.'/messages', $body)->assertNotFound();
    }

    public function test_list_paginates_archived_chats_by_project_and_snapshot_carries_only_public_conversation_metadata(): void
    {
        $user = User::factory()->create();
        $key = $this->token($user, 'device');
        $project = Project::create(['user_id' => $user->id, 'external_id' => 'project', 'name' => 'Project']);
        foreach ([false, true, false] as $archived) {
            Conversation::create(['user_id' => $user->id, 'external_id' => (string) Str::uuid(), 'client_id' => 'device',
                'project_ref_id' => $project->id, 'title' => 'Chat', 'archived_at' => $archived ? now() : null]);
        }
        Conversation::create(['user_id' => $user->id, 'external_id' => (string) Str::uuid(), 'client_id' => 'device', 'title' => 'Other project']);
        $url = '/api/v1/conversations?project_id=project&include_archived=1&limit=2&after=';
        $first = $this->withHeader('X-Api-Key', $key)->getJson($url.'0')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', true)->json();
        $this->getJson($url.$first['meta']['next_cursor'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', false);
        $snapshot = ['schema_version' => 1, 'project' => ['name' => 'Project', 'summary' => '', 'goals' => [], 'createdAt' => 1, 'updatedAt' => 2],
            'messages' => [['id' => 'message', 'conversationId' => 'chat', 'role' => 'user', 'content' => 'Public', 'ts' => 1, 'createdAt' => 1, 'visibility' => 'visible']],
            'memories' => [], 'summaries' => [], 'conversations' => [['id' => 'chat', 'title' => 'Title', 'createdAt' => 1, 'updatedAt' => 2, 'archivedAt' => 2]]];
        $this->putJson('/api/v1/projects/'.$project->id.'/cloud', ['expected_revision' => 0, 'snapshot' => $snapshot])->assertOk();
        $this->getJson('/api/v1/projects/'.$project->id.'/cloud')->assertJsonPath('data.snapshot.messages.0.conversationId', 'chat')->assertJsonPath('data.snapshot.conversations.0.archivedAt', 2);
        $snapshot['conversations'][0]['draft'] = 'Never upload';
        $this->putJson('/api/v1/projects/'.$project->id.'/cloud', ['expected_revision' => 1, 'snapshot' => $snapshot])->assertUnprocessable();
    }

    private function token(User $user, string $id): string
    {
        Device::create(['user_id' => $user->id, 'device_id' => $id, 'name' => $id]);

        return ApiKey::mint(['user_id' => $user->id, 'device_id' => $id, 'name' => $id,
            'abilities' => ['brain.read', 'brain.write', 'device.connect'], 'active' => true])['plain'];
    }
}
