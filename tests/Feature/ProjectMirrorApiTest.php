<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\Project;
use App\Models\User;
use App\Services\CoordinatedDeviceJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectMirrorApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private string $master;

    private string $worker;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('cloud-projects');
        $user = User::factory()->create();
        $this->master = $this->device($user, 'master');
        $this->worker = $this->device($user, 'worker');
        $this->project = Project::create(['user_id' => $user->id, 'external_id' => 'full-folder', 'name' => 'Full folder']);
        $this->base = '/api/v1/projects/'.$this->project->id.'/mirror';
        $this->withHeader('X-Api-Key', $this->master)->postJson('/api/v1/coordination/heartbeat', ['available' => true, 'busy' => false, 'preferred' => true])->assertOk();
    }

    public function test_full_folder_includes_hidden_secrets_git_binary_empty_and_case_variant_names_privately(): void
    {
        $entries = [$this->file('.env', "  PRIVATE_TOKEN=example\n"), ['path' => '.git', 'type' => 'directory'],
            $this->file('.git/index', "\0\xFF\x01binary"), $this->file('node_modules/package/index.js', 'module'),
            $this->file('Empty', ''), $this->file('empty', 'case-sensitive'),
            ['path' => 'link', 'type' => 'symlink', 'target' => '../outside', 'mode' => 41471, 'mtimeMs' => 1]];
        $published = $this->publish($entries, 0);
        $this->assertSame(1, $published['revision']);
        $this->withHeader('X-Api-Key', $this->worker)->getJson($this->base.'/manifests/'.$published['manifest_id'].'?limit=3')
            ->assertOk()->assertJsonCount(3, 'data.entries')->assertJsonPath('data.next_offset', 3);
        $all = $this->getJson($this->base.'/manifests/'.$published['manifest_id'])->assertOk()->json('data.entries');
        $this->assertSame(CoordinatedDeviceJobs::hash($entries), CoordinatedDeviceJobs::hash($all));
        $sha = $entries[0]['sha256'];
        $this->head($this->base.'/chunks/'.$sha)->assertOk()->assertHeader('X-Content-SHA256', $sha)
            ->assertHeader('Content-Length', (string) strlen("  PRIVATE_TOKEN=example\n"))->assertContent('');
        $this->get($this->base.'/chunks/'.$sha)->assertOk()->assertContent("  PRIVATE_TOKEN=example\n");
        foreach (Storage::disk('cloud-projects')->allFiles() as $path) {
            $this->assertStringNotContainsString('PRIVATE_TOKEN', Storage::disk('cloud-projects')->get($path));
        }
        $this->assertStringNotContainsString('.env', DB::table('project_mirror_entries')->first()->entry);
        $this->withHeader('X-Api-Key', $this->worker)->postJson($this->base.'/lease', ['master_epoch' => 1, 'expected_revision' => 1])->assertForbidden();
        $other = $this->device(User::factory()->create(['role' => 'admin']), 'foreign');
        $this->withHeader('X-Api-Key', $other)->getJson($this->base)->assertNotFound();
        $this->get($this->base.'/chunks/'.$sha)->assertNotFound();
        $this->head($this->base.'/chunks/'.$sha)->assertNotFound();
    }

    public function test_chunk_boundary_integrity_and_published_manifest_are_enforced(): void
    {
        $entry = $this->file('binary.dat', str_repeat("\x01", 8_388_608));
        $sha = hash('sha256', 'wrong');
        $this->raw($sha, 'tampered')->assertUnprocessable();
        $this->raw(hash('sha256', str_repeat('x', 8_388_609)), str_repeat('x', 8_388_609))->assertStatus(413);
        $published = $this->publish([$entry], 0);
        $this->putJson($this->base.'/manifests/'.$published['manifest_id'].'/entries', ['operation_id' => (string) Str::uuid(), 'entries' => []])->assertConflict();
        $this->postJson($this->base.'/lease', ['master_epoch' => 1, 'expected_revision' => 0])->assertConflict();
        $lease = $this->lease(1);
        $this->travel(46)->seconds();
        $this->postJson($this->base.'/manifests', ['operation_id' => (string) Str::uuid(), 'base_revision' => 1,
            'master_epoch' => 1, 'lease_id' => $lease, 'entries' => []])->assertConflict();
    }

    public function test_paged_manifest_retry_is_idempotent_and_malformed_or_traversal_input_rejected(): void
    {
        $body = ['operation_id' => (string) Str::uuid(), 'base_revision' => 0, 'master_epoch' => 1, 'draft' => true, 'entries' => []];
        $draft = $this->postJson($this->base.'/manifests', $body)->assertCreated()->json('data.manifest_id');
        $this->postJson($this->base.'/manifests', $body)->assertCreated()->assertJsonPath('data.manifest_id', $draft);
        $page = ['operation_id' => (string) Str::uuid(), 'entries' => [$this->file('space name', 'Exact')]];
        $this->putJson($this->base.'/manifests/'.$draft.'/entries', $page)->assertOk();
        $this->putJson($this->base.'/manifests/'.$draft.'/entries', $page)->assertOk()->assertJsonPath('data.entry_count', 1);
        $this->putJson($this->base.'/manifests/'.$draft.'/entries', ['operation_id' => $page['operation_id'], 'entries' => []])->assertConflict();
        $bad = $page;
        $bad['operation_id'] = (string) Str::uuid();
        $bad['entries'][0]['path'] = '../escape';
        $this->putJson($this->base.'/manifests/'.$draft.'/entries', $bad)->assertUnprocessable();
        $this->json('POST', $this->base.'/manifests', ['scalar'])->assertUnprocessable();
        $this->assertSame(1, DB::table('project_mirror_entries')->where('manifest_id', $draft)->count());
    }

    public function test_disjoint_assistant_changes_merge_but_divergent_overlap_preserves_both_snapshots(): void
    {
        $a = $this->file('a', 'base-a');
        $b = $this->file('b', 'base-b');
        $base = $this->publish([$a, $b], 0);
        $proposal = $this->proposal([$this->file('a', 'assistant-a'), $b], 1);
        $current = $this->publish([$a, $this->file('b', 'master-b')], 1);
        $body = ['operation_id' => (string) Str::uuid(), 'master_epoch' => 1, 'lease_id' => $this->lease(2), 'expected_revision' => 2];
        $merged = $this->postJson($this->base.'/manifests/'.$proposal.'/publish', $body)->assertOk()->assertJsonPath('data.revision', 3)->json('data');
        $this->postJson($this->base.'/manifests/'.$proposal.'/publish', $body)->assertOk()->assertJsonPath('data.manifest_id', $merged['manifest_id']);
        $entries = collect($this->getJson($this->base.'/manifests/'.$merged['manifest_id'])->json('data.entries'))->keyBy('path');
        $this->assertSame(hash('sha256', 'assistant-a'), $entries['a']['sha256']);
        $this->assertSame(hash('sha256', 'master-b'), $entries['b']['sha256']);
        $conflicting = $this->proposal([$this->file('a', 'another-a'), $b], 1);
        $this->withHeader('X-Api-Key', $this->master)->postJson($this->base.'/manifests/'.$conflicting.'/publish', [
            'operation_id' => (string) Str::uuid(), 'master_epoch' => 1, 'lease_id' => $this->lease(3), 'expected_revision' => 3])
            ->assertConflict()->assertJsonPath('code', 'mirror_merge_conflict')->assertJsonPath('data.proposal_manifest_id', $conflicting);
        $this->getJson($this->base)->assertJsonPath('data.revision', 3)->assertJsonPath('data.manifest_id', $merged['manifest_id']);
        $this->getJson($this->base.'/manifests/'.$conflicting)->assertJsonPath('data.status', 'proposed');
        $this->assertSame(5, DB::table('project_mirror_manifests')->count());
    }

    private function proposal(array $entries, int $base): string
    {
        $job = new DeviceJob;
        $job->protocol_version = 2;
        $job->fill(['user_id' => $this->project->user_id, 'device_id' => Device::where('device_id', 'worker')->value('id'),
            'project_id' => $this->project->id, 'public_id' => (string) Str::uuid(), 'tool_profile' => 'chat.turn', 'payload' => [],
            'payload_hash' => str_repeat('0', 64), 'status' => 'running', 'master_epoch' => 1]);
        $job->save();

        return $this->withHeader('X-Api-Key', $this->worker)->postJson($this->base.'/manifests', ['operation_id' => (string) Str::uuid(),
            'base_revision' => $base, 'entries' => $entries, 'proposal' => true, 'job_id' => $job->public_id])->assertCreated()->json('data.manifest_id');
    }

    private function publish(array $entries, int $base): array
    {
        return $this->withHeader('X-Api-Key', $this->master)->postJson($this->base.'/manifests', ['operation_id' => (string) Str::uuid(),
            'base_revision' => $base, 'master_epoch' => 1, 'lease_id' => $this->lease($base), 'entries' => $entries])->assertCreated()->json('data');
    }

    private function lease(int $revision): string
    {
        return $this->withHeader('X-Api-Key', $this->master)->postJson($this->base.'/lease', ['master_epoch' => 1, 'expected_revision' => $revision])->assertOk()->json('data.lease_id');
    }

    private function file(string $path, string $content): array
    {
        $sha = hash('sha256', $content);
        if ($content !== '') {
            $this->raw($sha, $content)->assertOk();
        }

        return ['path' => $path, 'type' => 'file', 'size' => strlen($content), 'sha256' => $sha,
            'chunks' => $content === '' ? [] : [['sha256' => $sha, 'size' => strlen($content)]]];
    }

    private function raw(string $sha, string $content)
    {
        return $this->call('PUT', $this->base.'/chunks/'.$sha, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_API_KEY' => $this->master], $content);
    }

    private function device(User $user, string $id): string
    {
        $key = ApiKey::mint(['user_id' => $user->id, 'name' => $id, 'device_id' => $id,
            'abilities' => ['device.connect', 'device.jobs.read', 'device.jobs.write', 'brain.read', 'brain.write'], 'active' => true]);
        Device::create(['user_id' => $user->id, 'device_id' => $id, 'name' => $id, 'status' => 'online']);

        return $key['plain'];
    }
}
