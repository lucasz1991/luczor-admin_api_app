<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\ProjectCloudFile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CloudProjectFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CloudProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_devices_share_a_private_snapshot_and_stale_saves_cannot_overwrite_it(): void
    {
        $user = User::factory()->create();
        $first = $this->token($user, 'desktop');
        $second = $this->token($user, 'laptop');
        $project = $this->createProject($first);
        $url = '/api/v1/projects/'.$project.'/cloud';

        $this->withHeader('X-Api-Key', $first)->putJson($url, ['expected_revision' => 0, 'snapshot' => $this->snapshot()])
            ->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.updated_by_device', 'desktop');
        $this->withHeader('X-Api-Key', $second)->getJson($url)->assertOk()
            ->assertJsonPath('data.snapshot.messages.0.content', "  public content\n")
            ->assertJsonPath('data.snapshot.project.goals.0.title', 'Shared goal');
        $updated = $this->snapshot('From laptop');
        $this->withHeader('X-Api-Key', $second)->putJson($url, ['expected_revision' => 1, 'snapshot' => $updated])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.updated_by_device', 'laptop');
        $this->withHeader('X-Api-Key', $first)->putJson($url, ['expected_revision' => 1, 'snapshot' => $this->snapshot('Stale desktop')])
            ->assertConflict()->assertJsonPath('code', 'project_revision_conflict')
            ->assertJsonPath('data.snapshot.project.name', 'From laptop');
        $this->withHeader('X-Api-Key', $second)->getJson('/api/v1/projects?scope=cloud')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.name', 'From laptop')
            ->assertJsonMissingPath('data.data.0.cloud_snapshot');
        $this->withHeader('X-Api-Key', $second)->patchJson('/api/v1/projects/'.$project, ['name' => 'Revision bypass'])
            ->assertConflict()->assertJsonPath('code', 'project_revision_required');
        $this->assertStringNotContainsString('public content', DB::table('projects')->where('id', $project)->value('cloud_snapshot'));
    }

    public function test_cloud_resources_are_isolated_by_user_and_api_scope(): void
    {
        $owner = User::factory()->create();
        $ownerKey = $this->token($owner);
        $otherKey = $this->token(User::factory()->create());
        $readKey = $this->token($owner, 'reader', ['brain.read']);
        $project = $this->createProject($ownerKey);
        $cloud = '/api/v1/projects/'.$project.'/cloud';
        $this->withHeader('X-Api-Key', $ownerKey)->putJson($cloud, ['expected_revision' => 0, 'snapshot' => $this->snapshot()])->assertOk();
        $this->withHeader('X-Api-Key', $otherKey)->getJson($cloud)->assertNotFound();
        $this->withHeader('X-Api-Key', $otherKey)->putJson($cloud, ['expected_revision' => 1, 'snapshot' => $this->snapshot()])->assertNotFound();
        $this->withHeader('X-Api-Key', $otherKey)->getJson('/api/v1/projects?scope=cloud')->assertOk()->assertJsonPath('data.total', 0);
        $this->withHeader('X-Api-Key', $readKey)->putJson($cloud, ['expected_revision' => 1, 'snapshot' => $this->snapshot()])->assertForbidden();
        $this->withHeader('X-Api-Key', $readKey)->getJson($cloud)->assertOk();
        $this->withHeader('X-Api-Key', $otherKey)->getJson('/api/v1/projects/'.$project.'/files')->assertNotFound();
        $this->withHeader('X-Api-Key', $otherKey)->putJson('/api/v1/projects/'.$project.'/files', ['path' => 'a.txt', 'content' => 'leak', 'expected_revision' => 0])->assertNotFound();
    }

    public function test_cloud_snapshot_rejects_device_paths_tool_data_and_duplicate_message_ids(): void
    {
        $token = $this->token(User::factory()->create());
        $project = $this->createProject($token);
        $url = '/api/v1/projects/'.$project.'/cloud';
        $snapshot = $this->snapshot();
        $snapshot['project']['workspaceRoot'] = 'C:/device-only';
        $this->putJson($url, ['expected_revision' => 0, 'snapshot' => $snapshot])->assertUnprocessable();
        $snapshot = $this->snapshot();
        $snapshot['messages'][0]['role'] = 'tool';
        $this->putJson($url, ['expected_revision' => 0, 'snapshot' => $snapshot])->assertUnprocessable();
        $snapshot = $this->snapshot();
        $snapshot['messages'][0]['meta'] = ['dataHandling' => 'ephemeral'];
        $this->putJson($url, ['expected_revision' => 0, 'snapshot' => $snapshot])->assertUnprocessable();
        $snapshot = $this->snapshot();
        $snapshot['messages'][] = $snapshot['messages'][0];
        $this->putJson($url, ['expected_revision' => 0, 'snapshot' => $snapshot])->assertUnprocessable();
        $this->assertSame(0, Project::findOrFail($project)->cloud_revision);
    }

    public function test_private_files_preserve_exact_text_and_reject_conflicting_replacements_and_resurrection(): void
    {
        Storage::fake('cloud-projects');
        $user = User::factory()->create();
        $first = $this->token($user, 'desktop');
        $second = $this->token($user, 'laptop');
        $project = $this->createProject($first, true);
        $url = '/api/v1/projects/'.$project.'/files';
        $text = "  title\n\n";
        $this->putJson($url, ['path' => 'docs/Plan.md', 'content' => $text, 'expected_revision' => 0])
            ->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.sha256', hash('sha256', $text));
        $file = ProjectCloudFile::firstOrFail();
        $ciphertext = Storage::disk('cloud-projects')->get($file->storage_key);
        $this->assertStringNotContainsString('title', $ciphertext);
        $this->withHeader('X-Api-Key', $second)->getJson($url.'/content?path=docs%2FPlan.md')
            ->assertOk()->assertJsonPath('data.content', $text)->assertHeader('Cache-Control', 'no-store, private');
        $this->putJson($url, ['path' => 'docs/Plan.md', 'content' => 'laptop change', 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->withHeader('X-Api-Key', $first)->putJson($url, ['path' => 'docs/plan.md', 'content' => 'stale', 'expected_revision' => 1])
            ->assertConflict()->assertJsonPath('code', 'project_file_revision_conflict')->assertJsonPath('data.revision', 2);
        $this->deleteJson($url, ['path' => 'docs/Plan.md', 'expected_revision' => 2])->assertOk()
            ->assertJsonPath('data.deleted', true)->assertJsonPath('data.revision', 3);
        $this->withHeader('X-Api-Key', $second)->putJson($url, ['path' => 'docs/Plan.md', 'content' => 'resurrection', 'expected_revision' => 2])->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'/content?path=docs%2FPlan.md')->assertNotFound();
        $this->assertCount(0, Storage::disk('cloud-projects')->allFiles());
        $this->putJson($url, ['path' => 'docs/Plan.md', 'content' => '', 'expected_revision' => 3])->assertOk()->assertJsonPath('data.bytes', 0);
        $this->getJson($url.'/content?path=docs%2FPlan.md')->assertOk()->assertJsonPath('data.content', '');
    }

    public function test_private_file_paths_sizes_and_quota_are_checked_before_writes(): void
    {
        Storage::fake('cloud-projects');
        $token = $this->token(User::factory()->create());
        $project = $this->createProject($token, true);
        $url = '/api/v1/projects/'.$project.'/files';
        foreach (['../leak.txt', '/absolute', 'C:/device/file', 'dir\\file', 'a/../../b', '.env', '.git/config', 'con.txt', 'dir/file.'] as $path) {
            $this->putJson($url, ['path' => $path, 'content' => 'blocked', 'expected_revision' => 0])->assertUnprocessable();
        }
        $this->putJson($url, ['path' => 'large.txt', 'content' => str_repeat('x', CloudProjectFiles::MAX_FILE_BYTES + 1), 'expected_revision' => 0])->assertUnprocessable();
        $this->putJson($url, ['path' => 'nul.txt', 'content' => "binary\0data", 'expected_revision' => 0])->assertUnprocessable();
        ProjectCloudFile::create([
            'project_id' => $project, 'path' => 'full.txt', 'path_key' => hash('sha256', 'full.txt'),
            'revision' => 1, 'bytes' => CloudProjectFiles::MAX_PROJECT_BYTES, 'deleted' => false,
        ]);
        $this->putJson($url, ['path' => 'overflow.txt', 'content' => 'overflow', 'expected_revision' => 0])->assertUnprocessable();
        $this->assertCount(0, Storage::disk('cloud-projects')->allFiles());
    }

    public function test_admin_device_does_not_import_another_users_cloud_project(): void
    {
        $ownerKey = $this->token(User::factory()->create());
        $project = $this->createProject($ownerKey, true);
        $adminKey = $this->token(User::factory()->create(['role' => 'admin']));
        $this->withHeader('X-Api-Key', $adminKey)->getJson('/api/v1/projects?scope=cloud')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/projects/'.$project.'/cloud')->assertNotFound();
        // The established administrative metadata view continues to work.
        $this->getJson('/api/v1/projects/'.$project)->assertOk()->assertJsonMissingPath('data.cloud_snapshot');
    }

    public function test_failed_file_transaction_removes_the_uncommitted_private_blob(): void
    {
        Storage::fake('cloud-projects');
        $key = $this->token(User::factory()->create());
        $project = $this->createProject($key, true);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic failed transaction'));
        $this->putJson('/api/v1/projects/'.$project.'/files', ['path' => 'failed.md', 'content' => 'must not remain', 'expected_revision' => 0])
            ->assertInternalServerError();
        $this->assertDatabaseCount('project_cloud_files', 0);
        $this->assertCount(0, Storage::disk('cloud-projects')->allFiles());
    }

    public function test_snapshot_size_limit_applies_before_persisting_any_revision(): void
    {
        $key = $this->token(User::factory()->create());
        $project = $this->createProject($key);
        $snapshot = $this->snapshot();
        $snapshot['project']['summary'] = str_repeat('x', 5_242_881);
        $this->putJson('/api/v1/projects/'.$project.'/cloud', ['expected_revision' => 0, 'snapshot' => $snapshot])
            ->assertUnprocessable()->assertJsonValidationErrors('snapshot');
        $this->assertSame(0, Project::findOrFail($project)->cloud_revision);
    }

    private function createProject(string $key, bool $cloud = false): int
    {
        $id = $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/projects', ['external_id' => 'project-shared', 'name' => 'Cloud project'])
            ->assertCreated()->json('data.id');
        if ($cloud) {
            $this->putJson('/api/v1/projects/'.$id.'/cloud', ['expected_revision' => 0, 'snapshot' => $this->snapshot()])->assertOk();
        }

        return $id;
    }

    private function token(User $user, string $device = 'test-device', array $abilities = ['brain.read', 'brain.write']): string
    {
        return ApiKey::mint(['user_id' => $user->id, 'device_id' => $device, 'name' => 'Cloud project device', 'abilities' => $abilities, 'active' => true])['plain'];
    }

    private function snapshot(string $name = 'Shared project'): array
    {
        return [
            'schema_version' => 1,
            'project' => ['name' => $name, 'summary' => '', 'createdAt' => 1, 'updatedAt' => 2, 'goals' => [
                ['id' => 'goal-1', 'title' => 'Shared goal', 'status' => 'open', 'createdAt' => 1, 'updatedAt' => 1],
            ]],
            'messages' => [['id' => 'message-1', 'role' => 'assistant', 'content' => "  public content\n", 'ts' => 1, 'createdAt' => 1, 'visibility' => 'visible']],
            'memories' => [['id' => 'memory-1', 'kind' => 'note', 'key' => 'Project note', 'value' => 'Shared knowledge', 'priority' => 3, 'active' => true, 'createdAt' => 1, 'updatedAt' => 1, 'source' => ['by' => 'user']]],
            'summaries' => [['id' => 'summary-1', 'text' => 'Project context', 'createdAt' => 1]],
        ];
    }
}
