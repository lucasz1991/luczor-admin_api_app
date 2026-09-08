<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\MemoryLink;
use App\Models\MemoryWriteEvent;
use App\Models\User;
use App\Services\MemoryOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MemoryPriorityAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_named_priority_persists_and_roundtrips_without_changing_the_write_identity(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $key = ApiKey::mint(['user_id' => $user->id, 'name' => 'Memory test', 'abilities' => ['brain.write', 'brain.read'], 'active' => true]);
        $body = ['scope' => 'user', 'content' => 'Antworten auf Deutsch.', 'priority' => 'critical', 'write_id' => 'priority-write', 'write_intent' => 'explicit'];
        $headers = ['Authorization' => 'Bearer '.$key['plain']];
        $first = $this->postJson('/api/v1/memory/remember', $body, $headers)->assertCreated()->assertJsonPath('priority', 'critical');
        $this->postJson('/api/v1/memory/remember', $body, $headers)->assertCreated()->assertJsonPath('memory_link_id', $first->json('memory_link_id'));
        $this->assertDatabaseCount('memory_links', 1);
        $this->assertDatabaseCount('memory_write_events', 1);
        $this->assertDatabaseHas('memory_links', ['id' => $first->json('memory_link_id'), 'importance' => 1]);
        $this->postJson('/api/v1/memory/recall', ['scope' => 'user', 'query' => 'Deutsch'], $headers)
            ->assertOk()->assertJsonPath('data.0.priority', 'critical')->assertJsonPath('data.0.priority_label', 'Kritisch');
        $this->postJson('/api/v1/memory/remember', [...$body, 'priority' => 'background'], $headers)->assertConflict();
        $this->postJson('/api/v1/memory/remember', [...$body, 'priority' => 'urgent'], $headers)->assertUnprocessable();
    }

    public function test_equal_relevance_prefers_priority_and_omits_other_users_and_projects(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $memory = app(MemoryOrchestrator::class);
        foreach ([['normal', $user, 'p1'], ['critical', $user, 'p1'], ['critical', $other, 'p1'], ['critical', $user, 'p2']] as $index => [$priority, $owner, $project]) {
            $memory->remember(['user_id' => $owner->id, 'scope' => 'project', 'project_id' => $project,
                'write_id' => 'rank-'.$index, 'external_id' => 'rank-'.$index, 'content' => 'Navigation Hinweis '.$index, 'priority' => $priority]);
        }
        $hits = $memory->recall('Navigation', 'project', ['user_id' => $user->id, 'project_id' => 'p1']);
        $this->assertSame(['rank-1', 'rank-0'], array_column($hits, 'id'));
        $this->assertSame([], $memory->recall('', 'user', ['user_id' => $user->id]));
    }

    public function test_analysis_is_read_only_bounded_and_partitioned_and_recall_deduplicates(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $memory = app(MemoryOrchestrator::class);
        $first = $memory->remember(['user_id' => $user->id, 'scope' => 'project', 'project_id' => 'p1',
            'content' => 'Bestätigte Projektentscheidung.', 'write_id' => 'analysis-one', 'priority' => 'high'])->link;
        $duplicate = $first->replicate(['external_id', 'idempotency_key', 'write_fingerprint']);
        $duplicate->external_id = 'legacy-duplicate';
        $duplicate->save();
        $expired = $first->replicate(['external_id', 'idempotency_key', 'write_fingerprint']);
        $expired->external_id = 'expired-review';
        $expired->expires_at = now()->subMinute();
        $expired->save();
        $memory->remember(['user_id' => $other->id, 'scope' => 'project', 'project_id' => 'p1', 'content' => 'Fremde Notiz.', 'write_id' => 'analysis-other']);
        $memory->remember(['user_id' => $user->id, 'scope' => 'project', 'project_id' => 'p2', 'content' => 'Anderes Projekt.', 'write_id' => 'analysis-p2']);
        $before = MemoryLink::orderBy('id')->get()->toJson();
        $events = MemoryWriteEvent::count();
        $report = $memory->analyze('project', ['user_id' => $user->id, 'project_id' => 'p1']);
        $this->assertSame(3, $report['analyzed']);
        $this->assertSame(1, $report['expired_count']);
        $this->assertCount(1, $report['duplicates']);
        $this->assertSame(0, $report['changed_records']);
        $this->assertSame($before, MemoryLink::orderBy('id')->get()->toJson());
        $this->assertSame($events, MemoryWriteEvent::count());
        $this->assertCount(1, $memory->recall('Projektentscheidung', 'project', ['user_id' => $user->id, 'project_id' => 'p1']));
        $this->assertSame(0, $memory->analyze('user', ['user_id' => $user->id])['analyzed']);
    }

    public function test_analysis_api_requires_read_permission_and_rejects_wide_scopes(): void
    {
        $user = User::factory()->create();
        $key = ApiKey::mint(['user_id' => $user->id, 'name' => 'Read memory', 'abilities' => ['brain.read'], 'active' => true]);
        $headers = ['Authorization' => 'Bearer '.$key['plain']];
        $this->postJson('/api/v1/memory/analyze', ['scope' => 'user'], $headers)->assertOk()->assertJsonPath('data.changed_records', 0);
        $this->postJson('/api/v1/memory/analyze', ['scope' => 'project'], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/memory/analyze', ['scope' => 'workspace'], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/memory/analyze', ['scope' => 'user'])->assertUnauthorized();
    }
}
